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

/**
 * @group maniphest
 */
final class ManiphestTransactionPreviewController extends ManiphestController {

  private $id;

  public function willProcessRequest(array $data) {
    $this->id = $data['id'];
  }

  public function processRequest() {

    $request = $this->getRequest();
    $user = $request->getUser();

    $comments = $request->getStr('comments');

    $task = id(new ManiphestTask())->load($this->id);
    if (!$task) {
      return new Aphront404Response();
    }

    $draft = id(new PhabricatorDraft())->loadOneWhere(
      'authorPHID = %s AND draftKey = %s',
      $user->getPHID(),
      $task->getPHID());
    if (!$draft) {
      $draft = new PhabricatorDraft();
      $draft->setAuthorPHID($user->getPHID());
      $draft->setDraftKey($task->getPHID());
    }
    $draft->setDraft($comments);
    $draft->save();

    $phids = array($user->getPHID());

    $action = $request->getStr('action');

    $transaction = new ManiphestTransaction();
    $transaction->setAuthorPHID($user->getPHID());
    $transaction->setComments($comments);
    $transaction->setTransactionType($action);

    $value = $request->getStr('value');
    switch ($action) {
      case ManiphestTransactionType::TYPE_OWNER:
        if (!$value) {
          $value = $user->getPHID();
        }
        $phids[] = $value;
        break;
      case ManiphestTransactionType::TYPE_PRIORITY:
        $transaction->setOldValue($task->getPriority());
        break;
    }
    $transaction->setNewValue($value);

    $handles = id(new PhabricatorObjectHandleData($phids))
      ->loadHandles();

    $transactions = array();
    $transactions[] = $transaction;

    $engine = PhabricatorMarkupEngine::newManiphestMarkupEngine();

    $transaction_view = new ManiphestTransactionListView();
    $transaction_view->setTransactions($transactions);
    $transaction_view->setHandles($handles);
    $transaction_view->setUser($user);
    $transaction_view->setMarkupEngine($engine);
    $transaction_view->setPreview(true);

    return id(new AphrontAjaxResponse())
      ->setContent($transaction_view->render());
  }

}
