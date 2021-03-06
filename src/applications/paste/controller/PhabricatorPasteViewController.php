<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

final class PhabricatorPasteViewController extends PhabricatorPasteController {

  private $id;
  private $handles;

  public function willProcessRequest(array $data) {
    $this->id = $data['id'];
  }

  public function processRequest() {
    $request = $this->getRequest();
    $user = $request->getUser();

    $paste = id(new PhabricatorPasteQuery())
      ->setViewer($user)
      ->withIDs(array($this->id))
      ->executeOne();
    if (!$paste) {
      return new Aphront404Response();
    }

    $file = id(new PhabricatorFile())->loadOneWhere(
      'phid = %s',
      $paste->getFilePHID());
    if (!$file) {
      return new Aphront400Response();
    }

    $forks = id(new PhabricatorPasteQuery())
      ->setViewer($user)
      ->withParentPHIDs(array($paste->getPHID()))
      ->execute();
    $fork_phids = mpull($forks, 'getPHID');

    $this->loadHandles(
      array_merge(
        array(
          $paste->getAuthorPHID(),
          $paste->getParentPHID(),
        ),
        $fork_phids));

    $header = $this->buildHeaderView($paste);
    $actions = $this->buildActionView($user, $paste, $file);
    $properties = $this->buildPropertyView($paste, $fork_phids);
    $source_code = $this->buildSourceCodeView($paste, $file);

    $nav = $this->buildSideNavView($paste);
    $nav->selectFilter('paste');

    $nav->appendChild(
      array(
        $header,
        $actions,
        $properties,
        $source_code,
      ));

    return $this->buildApplicationPage(
      $nav,
      array(
        'title' => $paste->getFullName(),
        'device' => true,
      ));
  }

  private function buildHeaderView(PhabricatorPaste $paste) {
    return id(new PhabricatorHeaderView())
      ->setObjectName('P'.$paste->getID())
      ->setHeader($paste->getTitle());
  }

  private function buildActionView(
    PhabricatorUser $user,
    PhabricatorPaste $paste,
    PhabricatorFile $file) {

    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $user,
      $paste,
      PhabricatorPolicyCapability::CAN_EDIT);

    return id(new PhabricatorActionListView())
      ->setUser($user)
      ->setObject($paste)
      ->addAction(
        id(new PhabricatorActionView())
          ->setName(pht('Fork This Paste'))
          ->setIcon('fork')
          ->setHref($this->getApplicationURI('?parent='.$paste->getID())))
      ->addAction(
        id(new PhabricatorActionView())
          ->setName(pht('View Raw File'))
          ->setIcon('file')
          ->setHref($file->getBestURI()))
      ->addAction(
        id(new PhabricatorActionView())
          ->setName(pht('Edit Paste'))
          ->setIcon('edit')
          ->setDisabled(!$can_edit)
          ->setWorkflow(!$can_edit)
          ->setHref($this->getApplicationURI('/edit/'.$paste->getID().'/')));
  }

  private function buildPropertyView(
    PhabricatorPaste $paste,
    array $child_phids) {

    $user = $this->getRequest()->getUser();
    $properties = new PhabricatorPropertyListView();

    $properties->addProperty(
      pht('Author'),
      $this->getHandle($paste->getAuthorPHID())->renderLink());

    $properties->addProperty(
      pht('Created'),
      phabricator_datetime($paste->getDateCreated(), $user));

    if ($paste->getParentPHID()) {
      $properties->addProperty(
        pht('Forked From'),
        $this->getHandle($paste->getParentPHID())->renderLink());
    }

    if ($child_phids) {
      $properties->addProperty(
        pht('Forks'),
        $this->renderHandlesForPHIDs($child_phids));
    }

    $descriptions = PhabricatorPolicyQuery::renderPolicyDescriptions(
      $user,
      $paste);

    $properties->addProperty(
      pht('Visible To'),
      $descriptions[PhabricatorPolicyCapability::CAN_VIEW]);

    return $properties;
  }

  private function buildSourceCodeView(
    PhabricatorPaste $paste,
    PhabricatorFile $file) {

    $language = $paste->getLanguage();
    $source = $file->loadFileData();

    if (empty($language)) {
      $source = PhabricatorSyntaxHighlighter::highlightWithFilename(
        $paste->getTitle(),
        $source);
    } else {
      $source = PhabricatorSyntaxHighlighter::highlightWithLanguage(
        $language,
        $source);
    }

    $lines = explode("\n", $source);

    return id(new PhabricatorSourceCodeView())
      ->setLines($lines);
  }

}
