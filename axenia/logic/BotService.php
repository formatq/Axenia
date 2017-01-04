<?php

class BotService
{

    private $db;

    /**
     * Axenia constructor.
     * @param $db BotDao
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Handle exceptions
     * TODO optimized this, need not only message but inline and etc
     */
    public function handleException(Exception $e, $update)
    {
        $this->db->disconnect();
        if (defined('LOG_CHAT_ID')) {
            $message = $update["message"];
            $chat = $message['chat'];
            $from = $message['from'];
            $errorMsg = "<b>Caught Exception!</b>\n";
            $temp = "On message of user :uName [<i>:uid</i>] in group ':cName' [<i>:cid</i>]\n";
            $errorMsg .= Util::insert($temp,
                array('uid' => $from['id'],
                    'uName' => Util::getFullNameUser($from),
                    'cid' => $chat['id'],
                    'cName' => $chat['type'] == 'private' ? Util::getFullNameUser($chat) : $chat['title'])
            );
            $errorMsg .= Util::insert("<b>Error message:</b> <code>:0</code>\n<i>Error description:</i>\n<pre>:1</pre>", array($e->getMessage(), $e));
            Request::sendHtmlMessage(LOG_CHAT_ID, $errorMsg);
        } else {
            throw $e;
        }
    }

// region -------------------- Users

    public function getUserID($username)
    {
        return $this->db->getUserID($username);
    }

    //todo
    public function insertOrUpdateUser($user)
    {
        return $this->db->insertOrUpdateUser($user);
    }

    public function rememberUser($user)
    {
        return $this->db->insertOrUpdateUser($user);
    }

    public function getUserName($id)
    {
        return $this->db->getUserName($id);
    }

    public function getUserList($query)
    {
        $users = $this->db->getUsersByName($query);
        if ($users != false) {
            $a = array_chunk($users, 4);
            $stack = array();
            foreach ($a as $user) {
                $userTitle = Util::getFullName($user[1], $user[2], $user[3]);
                $text = Lang::message("user.stat", array("user" => '👤' . $userTitle));
                array_push($stack, array('type' => 'article', 'id' => uniqid(), 'title' => $text, 'message_text' => $text . ":\r\n" . $this->getStats($user[0]), 'parse_mode' => 'HTML'));
            }

            return $stack;
        }

        return false;
    }

    //🔮Наебашил кармы: 3829
    //📊Место в рейтинге: 30
    //👥Заседает в группах: Axenia.Development, Perm Friends (http://telegram.me/permchat), Брейкинг Ньюс, КОРТ, НАШ ЧАТ, Плио, Флудиляторная
    //🏅Медальки: Кармодрочер x3, Карманьяк x3, Кармонстр x2,
    public function getStats($from_id, $chat_id = NULL)
    {
        $res =
            ($chat_id == NULL ? "" : ('📍 ' . Lang::message("user.stat.inchat") . $this->getUserLevel($from_id, $chat_id) . "\r\n")) .
            '🔮 ' . Lang::message("user.stat.sum") . round($this->db->SumKarma($from_id), 0) . "\r\n" .
            '📊 ' . Lang::message("user.stat.place") . $this->db->UsersPlace($from_id) . "\r\n" .
            '👥 ' . Lang::message("user.stat.membership") . implode(", ", $this->getUserGroup($from_id)) . "\r\n";
        if ($a = $this->getAllUserRewards($from_id)) {
            $res .= '🏅 ' . Lang::message("user.stat.rewards") . implode(", ", $a);
        }

        return $res;
    }

    public function getUserGroup($id)
    {
        if ($a = $this->db->getUserGroups($id)) {
            $a = array_chunk($a, 2);
            $stack = array();
            foreach ($a as $value) {
                array_push($stack, (empty($value[1])) ? $value[0] : "<a href='telegram.me/" . $value[1] . "'>" . $value[0] . "</a>");
            }

            return $stack;
        }

        return false;
    }

    public function isBot($username)
    {
        return Util::endsWith(strtolower($username), 'bot');
    }

//endregion

// region -------------------- Admins
    public function isAdminInChat($user_id, $chat)
    {
        if ($chat['type'] == 'private') return true;
        $admins = Request::getChatAdministrators($chat['id']);
        foreach ($admins as $admin) {
            if ($admin['user']['id'] == $user_id) return true;
        }

        return false;
    }

    public function isSilentMode($chat_id)
    {
        return $this->db->getSilentMode($chat_id);
    }

    public function toggleSilentMode($chat_id)
    {
        return $this->db->setSilentMode($chat_id, !$this->db->getSilentMode($chat_id));
    }


//endregion

// region -------------------- Lang

    /*
     * Type of chat, can be either “private”, “group”, “supergroup” or “channel”
     */
    public function getLang($id, $chatType)
    {
        if ($chatType == "private") {
            return $this->db->getUserLang($id);
        } elseif (Util::isInEnum("group,supergroup", $chatType)) {
            return $this->db->getChatLang($id);
        }

        return false;
    }

    public function setLang($id, $chatType, $lang)
    {
        if ($chatType == "private") {
            return $this->db->setUserLang($id, $lang);
        } elseif (Util::isInEnum("group,supergroup", $chatType)) {
            return $this->db->setChatLang($id, $lang);
        }

        return false;
    }


    public function initLang($chat_id, $chatType)
    {
        $isNewChat = false;
        $lang = $this->getLang($chat_id, $chatType);
        if ($lang === false) {
            $lang = Lang::defaultLangKey();
            $isNewChat = true;
        }
        Lang::init($lang);

        return $isNewChat;
    }

//endregion

// region -------------------- Chats

    public function getChatsIds()
    {
        return $this->db->getChatsIds();
    }

    public function rememberChat($chat, $adder_id = null)
    {
        $chat_id = $chat['id'];
        $chatType = $chat['type'];
        $title = $chat['title'];
        $username = $chat['username'];
        if (Util::isInEnum("group,supergroup", $chatType)) {
            $res = $this->db->insertOrUpdateChat($chat_id, $title, $username);
            if ($this->db->getChatLang($chat_id) === false) {
                $lang = Lang::defaultLangKey();
                if ($adder_id != null) {
                    $lang = $this->db->getUserLang($adder_id); //получение языка добавителя
                    if ($lang === false) {
                        $lang = Lang::defaultLangKey();
                    }
                }
                $this->db->setChatLang($chat_id, $lang);
                Lang::init($lang);
            }

            return $res;
        }

        return false;
    }

    public function getChatMembersCount($chat_id)
    {
        return $this->db->getMembersCount($chat_id);
    }

    public function deleteChat($chat_id)
    {
        if ($this->db->deleteChat($chat_id)) {
            if ($this->db->deleteAllKarmaInChat($chat_id)) {
                if ($this->db->deleteAllRewardsInChat($chat_id)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function deleteUserDataInChat($user_id, $chat_id)
    {
        if ($this->db->deleteUserKarmaInChat($user_id, $chat_id)) {
            if ($this->db->deleteUserRewardsInChat($user_id, $chat_id)) {
                return true;
            }
        }

        return false;
    }


    public function getGroupName($chat_id)
    {
        return $this->db->getGroupName($chat_id);
    }

    public function getGroupsMistakes()
    {
        return $this->db->getGroupsMistakes();
    }


//endregion

//region -------------------- Karma

    public function getTop($chat_id, $limit = 5)
    {
        $out = Lang::message('karma.top.title', array("chatName" => $this->db->getGroupName($chat_id)));
        $top = $this->db->getTop($chat_id, $limit);
        $a = array_chunk($top, 4);
        foreach ($a as $value) {
            $username = ($value[0] == "") ? $value[1] . " " . $value[2] : $value[0];
            $out .= Lang::message('karma.top.row', array("username" => $username, "karma" => $value[3]));
        }
        $out .= Lang::message('karma.top.footer', array("pathToSite" => PATH_TO_SITE, "chatId" => $chat_id));

        return $out;
    }

    public function setLevelByUsername($username, $chat_id, $newLevel)
    {
        $user_id = $this->db->getUserID($username);
        if ($user_id !== false) {
            if ($this->db->setUserLevel($user_id, $chat_id, $newLevel)) {
                return Lang::message('karma.manualSet', array($username, $user_id, $chat_id, $newLevel));
            }
        }

        return Lang::message('bot.error');
    }

    public function setLevel($user_id, $chat_id, $newLevel)
    {
        return $this->db->setUserLevel($user_id, $chat_id, $newLevel);
    }

    public function getUserLevel($from_id, $chat_id)
    {
        $fromLevelResult = $this->db->getUserLevel($from_id, $chat_id);
        if (!$fromLevelResult[0]) {
            $this->db->setUserLevel($from_id, $chat_id, 0);

            return 0;
        } else {
            return $fromLevelResult[0];
        }
    }

    public function getAllKarmaPair()
    {
        $pairs = $this->db->getAllKarmaPair();
        if ($pairs !== false) {
            return array_chunk($pairs, 2);
        }

        return false;
    }

    private function createHandleKarmaResult($good, $msg, $level)
    {
        return array('good' => $good, 'msg' => $msg, 'newLevel' => $level);
    }

    public function handleKarma($isRise, $from, $to, $chat_id)
    {
        $newLevel = null;
        if ($from == $to) return $this->createHandleKarmaResult(true, Lang::message('karma.yourself'), $newLevel);

        $fromLevel = $this->getUserLevel($from, $chat_id);

        if ($fromLevel < 0) return $this->createHandleKarmaResult(true, Lang::message('karma.tooSmallKarma'), $newLevel);

        $userFrom = $this->getUserName($from);
        $fromLevelSqrt = $fromLevel == 0 ? 1 : sqrt($fromLevel);
        $toLevel = $this->getUserLevel($to, $chat_id);

        $newLevel = round($toLevel + ($isRise ? $fromLevelSqrt : -$fromLevelSqrt), 1);

        $userTo = $this->getUserName($to);

        $res = $this->db->setUserLevel($to, $chat_id, $newLevel);
        if ($res) {
            $mod = $isRise ? 'karma.plus' : 'karma.minus';
            $msg = Lang::message($mod, array('from' => $userFrom, 'k1' => $fromLevel, 'to' => $userTo, 'k2' => $newLevel));

            return $this->createHandleKarmaResult(true, $msg, $newLevel);
        }

        return $this->createHandleKarmaResult(false, Lang::message('bot.error'), null);
    }


    public function handleKarmaFromBot($isRise, $user_id, $chat_id)
    {
        $user2 = $this->getUserName($user_id);

        if ($user2) {
            $toLevel = $this->getUserLevel($user_id, $chat_id);

            $newLevel = $isRise ? $toLevel + 1 : $toLevel - (($toLevel > 0 && $toLevel <= 1) ? 0.1 : 1);

            $res = $this->db->setUserLevel($user_id, $chat_id, $newLevel);
            if ($res) {
                $mod = $isRise ? 'karma.plus' : 'karma.minus';
                $msg = Lang::message($mod, array('from' => Lang::message('bot.name'), 'k1' => '∞', 'to' => $user2, 'k2' => $newLevel));

                return $this->createHandleKarmaResult(true, $msg, $newLevel);
            }

            return $this->createHandleKarmaResult(false, Lang::message('bot.error'), null);
        } else {
            return $this->createHandleKarmaResult(false, Lang::message('karma.unknownUser'), null);
        }
    }

//    public function deleteUserDataForChat($userId, $chatId)
//    {
//        if($this->db->deleteUserKarmaInChat($userId, $chatId)){
//            if($this->db->deleteUserRewardsInChat($userId, $chatId)){
//                return true;
//            }
//        }
//        return false;
//    }

//endregion

//region -------------------- Rewards

    public function handleRewards($currentCarma, $chat_id, $user_id)
    {
        $out = array();
        $oldRewards = $this->db->getUserRewardIds($user_id, $chat_id);

        $newRewards = array();
        if ($currentCarma >= 200) {
            array_push($newRewards, 2);
        }
        if ($currentCarma >= 500) {
            array_push($newRewards, 3);
        }
        if ($currentCarma >= 1000) {
            array_push($newRewards, 4);
        }

        $newRewards = array_diff($newRewards, $oldRewards);

        if (count($newRewards) > 0) {
            $groupName = $this->getGroupName($chat_id);
            $rewardTypes = $this->db->getRewardTypes($newRewards);
            $username = $this->getUserName($user_id);
            foreach ($rewardTypes as $type) {
                $desc = Lang::messageRu('reward.type.karma.desc', array($groupName, $type['karma_min']));

                $insertRes = $this->db->insertReward($type['id'], $desc, $user_id, $chat_id);
                if ($insertRes !== false) {
                    $msg = Lang::message('reward.new', array('user' => $username, 'path' => PATH_TO_SITE, 'user_id' => $user_id, 'title' => Lang::message('reward.type.' . $type['code'])));
                    array_push($out, $msg);
                }
            }
        }

        return $out;
    }

    /**
     * Формирует сообщения для отпарки по команде rewards
     * @param $user_id
     * @param $chat_id
     */
    public function getUserRewards($user_id, $chat_id)
    {
        if ($user_id != $chat_id) {
            $res = $this->db->getUserRewardsInChat($user_id, $chat_id);

        } else {
            $res = $this->db->getUserRewards($user_id);
        }

        if (count($res) > 0) {
        } else {
        }

    }

    public function getAllUserRewards($user_id)
    {
        $res = $this->db->getUserRewards($user_id);
        if ($res) {
            $stack = array();
            foreach (array_chunk($res, 2) as $a) {
                $text = Lang::message("reward.type." . $a[0]);
                if ($a[1] > 1) $text .= "<b> x" . $a[1] . "</b>";
                array_push($stack, $text);
            }

            return $stack;
        }

        return false;

    }

//endregion

}

?>