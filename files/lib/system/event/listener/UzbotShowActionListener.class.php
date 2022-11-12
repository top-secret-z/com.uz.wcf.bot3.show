<?php

/*
 * Copyright by Udo Zaydowicz.
 * Modified by SoftCreatR.dev.
 *
 * License: http://opensource.org/licenses/lgpl-license.php
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program; if not, write to the Free Software Foundation,
 * Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */
namespace show\system\event\listener;

use wcf\data\user\User;
use wcf\data\user\UserList;
use wcf\data\uzbot\log\UzbotLogEditor;
use wcf\system\background\BackgroundQueueHandler;
use wcf\system\background\uzbot\NotifyScheduleBackgroundJob;
use wcf\system\cache\builder\UzbotValidBotCacheBuilder;
use wcf\system\event\listener\IParameterizedEventListener;
use wcf\system\language\LanguageFactory;
use wcf\system\WCF;

/**
 * Listen to entry actions for Bot
 */
class UzbotShowActionListener implements IParameterizedEventListener
{
    /**
     * @inheritDoc
     */
    public function execute($eventObj, $className, $eventName, array &$parameters)
    {
        // check module
        if (!MODULE_UZBOT) {
            return;
        }

        $action = $eventObj->getActionName();
        $defaultLanguage = LanguageFactory::getInstance()->getLanguage(LanguageFactory::getInstance()->getDefaultLanguageID());

        // entry publication
        if ($action == 'triggerPublication') {
            // Read all active, valid bots, abort if none
            $bots = UzbotValidBotCacheBuilder::getInstance()->getData(['typeDes' => 'show_new']);
            if (!\count($bots)) {
                return;
            }

            // get entries
            $entries = $eventObj->getObjects();
            if (!\count($entries)) {
                return;
            }

            foreach ($entries as $editor) {
                $entry = $editor->getDecoratedObject();

                foreach ($bots as $bot) {
                    $affectedUserIDs = $countToUserID = $placeholders = [];
                    $count = 1;

                    // check for conditions, if exist, no guests
                    $conditions = $bot->getUserConditions();
                    if (\count($conditions)) {
                        if (!$entry->userID) {
                            continue;
                        } else {
                            $userList = new UserList();
                            $userList->getConditionBuilder()->add('user_table.userID = ?', [$entry->userID]);
                            foreach ($conditions as $condition) {
                                $condition->getObjectType()->getProcessor()->addUserCondition($condition, $userList);
                            }
                            $userList->readObjects();
                            if (!\count($userList->getObjects())) {
                                continue;
                            }
                        }
                    }

                    // affected user
                    $user = null;
                    if ($entry->userID) {
                        $user = new User($entry->userID);
                        if (!$user->userID) {
                            $user = null;
                        }
                    }

                    if ($user) {
                        $affectedUserIDs[] = $user->userID;
                        $countToUserID[$user->userID] = 1;
                    } else {
                        $placeholders['user-email'] = $placeholders['user-groups'] = 'wcf.user.guest';
                        $placeholders['user-name'] = $placeholders['user-profile'] = $placeholders['@user-profile'] = 'wcf.user.guest';
                        $placeholders['user-age'] = 'x';
                        $placeholders['user-id'] = 0;
                        $placeholders['translate'] = ['user-email', 'user-groups', 'user-name', 'user-profile', '@user-profile', 'user-age'];
                    }

                    $placeholders['count'] = 1;
                    $placeholders['count-user'] = $user ? $user->showEntrys : 0;
                    $placeholders['entry-category'] = $entry->getCategory()->getTitle();
                    $placeholders['entry-link'] = $entry->getLink();
                    $placeholders['entry-subject'] = $entry->getTitle();
                    $placeholders['entry-text'] = $entry->getSimplifiedFormattedMessage();

                    // log action
                    if ($bot->enableLog) {
                        // userIDs string
                        if (\count($affectedUserIDs)) {
                            $userIDs = \implode(', ', $affectedUserIDs);
                        } else {
                            $userIDs = $defaultLanguage->get('wcf.user.guest');
                        }

                        if (!$bot->testMode) {
                            UzbotLogEditor::create([
                                'bot' => $bot,
                                'count' => 1,
                                'additionalData' => $defaultLanguage->getDynamicVariable('wcf.acp.uzbot.log.user.affected', [
                                    'total' => 1,
                                    'userIDs' => $userIDs,
                                ]),
                            ]);
                        } else {
                            $result = $defaultLanguage->getDynamicVariable('wcf.acp.uzbot.log.test', [
                                'objects' => 1,
                                'users' => \count($affectedUserIDs),
                                'userIDs' => $userIDs,
                            ]);
                            if (\mb_strlen($result) > 64000) {
                                $result = \mb_substr($result, 0, 64000) . ' ...';
                            }
                            UzbotLogEditor::create([
                                'bot' => $bot,
                                'count' => 1,
                                'testMode' => 1,
                                'additionalData' => \serialize(['', '', $result]),
                            ]);
                        }
                    }

                    // check for and prepare notification
                    $notify = $bot->checkNotify(true, true);
                    if ($notify === null) {
                        continue;
                    }

                    // send to scheduler
                    $data = [
                        'bot' => $bot,
                        'placeholders' => $placeholders,
                        'affectedUserIDs' => $affectedUserIDs,
                        'countToUserID' => $countToUserID,
                    ];

                    $job = new NotifyScheduleBackgroundJob($data);
                    BackgroundQueueHandler::getInstance()->performJob($job);
                }
            }
        }

        // entry change
        if ($action == 'update' || $action == 'trash') {
            $bots = UzbotValidBotCacheBuilder::getInstance()->getData(['typeDes' => 'show_change']);
            if (!\count($bots)) {
                return;
            }

            $entries = $eventObj->getObjects();
            if (!\count($entries)) {
                return;
            }

            // get reason
            $reason = '';
            $params = $eventObj->getParameters();
            if (isset($params['editReason'])) {
                $reason = $params['editReason'];
            } elseif (isset($params['data']['reason'])) {
                $reason = $params['data']['reason'];
            }

            // changing user
            $user = WCF::getUser(); // changing user

            foreach ($entries as $editor) {
                $entry = $editor->getDecoratedObject();

                foreach ($bots as $bot) {
                    $affectedUserIDs = $countToUserID = $placeholders = [];
                    $count = 1;

                    $conditions = $bot->getUserConditions();
                    if (\count($conditions)) {
                        $userList = new UserList();
                        $userList->getConditionBuilder()->add('user_table.userID = ?', [$entry->userID]);
                        foreach ($conditions as $condition) {
                            $condition->getObjectType()->getProcessor()->addUserCondition($condition, $userList);
                        }
                        $userList->readObjects();
                        if (!\count($userList->getObjects())) {
                            continue;
                        }
                    }

                    // found one
                    $affectedUserIDs[] = $entry->userID;
                    $entryUser = null;
                    if ($entry->userID) {
                        $entryUser = new User($entry->userID);
                        if ($entryUser->userID) {
                            $countToUserID[$entry->userID] = $entryUser->showEntrys;
                        }
                    }

                    // set placeholders
                    $placeholders['action'] = $action == 'update' ? 'wcf.acp.uzbot.show.action.changed' : 'wcf.acp.uzbot.show.action.deleted';
                    $placeholders['count'] = 1;
                    $placeholders['count-user'] = $entryUser ? $entryUser->showEntrys : 0;
                    $placeholders['entry-id'] = $entry->entryID;
                    $placeholders['entry-link'] = $entry->getLink();
                    $placeholders['entry-subject'] = $entry->getTitle();
                    $placeholders['entry-text'] = $entry->getSimplifiedFormattedMessage();
                    $placeholders['entry-username'] = $entry->username;
                    $placeholders['changing-username'] = $user->username;
                    $placeholders['reason'] = $reason;
                    $placeholders['translate'] = ['action', 'changing-username'];

                    // log action
                    if ($bot->enableLog) {
                        if (!$bot->testMode) {
                            UzbotLogEditor::create([
                                'bot' => $bot,
                                'count' => 1,
                                'additionalData' => $defaultLanguage->getDynamicVariable('wcf.acp.uzbot.log.user.affected', [
                                    'total' => 1,
                                    'userIDs' => \implode(', ', $affectedUserIDs),
                                ]),
                            ]);
                        } else {
                            $result = $defaultLanguage->getDynamicVariable('wcf.acp.uzbot.log.test', [
                                'objects' => 1,
                                'users' => \count($affectedUserIDs),
                                'userIDs' => \implode(', ', $affectedUserIDs),
                            ]);

                            if (\mb_strlen($result) > 64000) {
                                $result = \mb_substr($result, 0, 64000) . ' ...';
                            }
                            UzbotLogEditor::create([
                                'bot' => $bot,
                                'count' => 1,
                                'testMode' => 1,
                                'additionalData' => \serialize(['', '', $result]),
                            ]);
                        }
                    }

                    // check for and prepare notification
                    $notify = $bot->checkNotify(true, true);
                    if ($notify === null) {
                        continue;
                    }

                    // send to scheduler
                    $data = [
                        'bot' => $bot,
                        'placeholders' => $placeholders,
                        'affectedUserIDs' => $affectedUserIDs,
                        'countToUserID' => $countToUserID,
                    ];

                    $job = new NotifyScheduleBackgroundJob($data);
                    BackgroundQueueHandler::getInstance()->performJob($job);
                }
            }
        }
    }
}
