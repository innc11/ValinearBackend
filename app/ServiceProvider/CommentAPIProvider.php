<?php

namespace ServiceProvider;

use Pimple\Container;
use Model\CommentNotificationModel;

class CommentAPIProvider extends Base\ServiceProviderBase
{
    public function onRegisterRule(Container &$container)
    {
        self::registerRule('GET',  '/comment', 'getComments');
        self::registerRule('POST', '/comment', 'publishComment');
    }

    public function publishComment(array $params)
    {
        $db = self::getService('database');
        $mailSystem = $this->container['mail'];

        $data = json_decode(file_get_contents('php://input'), true);

        // 检查必要的参数
        \Utils\Utils::checkMissingParameters2($data, ['key', 'label', 'nick', 'content', 'parent']);

        if (CAPTCHA_REQUIRED) { // 不在开发模式时需要检查验证码
            if (!isset($_COOKIE['captcha'])) {
                throw new \Exception\WrongCaptchaException('没有请求过验证码或者Cookie未能上传');
            } else if (!isset($data['captcha'])) {
                throw new \Exception\WrongCaptchaException('未输入验证码');
            } else {
                if (md5(strtolower($data['captcha'])) != $_COOKIE['captcha'])
                    throw new \Exception\WrongCaptchaException('验证码不正确');
            }
        }

        // 准备插入新的评论
        $insertionTime = time();
        $newComment = [
            'parent' => $data['parent']!=-1? $data['parent']:0,
            'key' => urldecode($data['key']),
            'label' => $data['label'],
            'nick' => $data['nick'],
            'mail' => $data['mail']? $data['mail']:'',
            'website' => $data['website']? $data['website']:'',
            'content' => $data['content'],
            'approved' => true,
            'time' => $insertionTime,
            'ip' => $_SERVER['REMOTE_ADDR'],
            'useragent' => $_SERVER['HTTP_USER_AGENT']
        ];

        // 插入评论到数据库里
        $sql = "INSERT INTO 'comments' (key, label, parent, nick, mail, website, content, approved, time, ip, useragent)".
                "VALUES (:key, :label, :parent, :nick, :mail, :website, :content, :approved, :time, :ip, :useragent)";
        $db->prepare($sql)->execute($newComment)->end();

        // 评论通知------------------------------
        $recipientMail = '';
        $recipientName = '';
        $recipientWebsite = '';

        // 如果是回复的一条评论而不是文章
        if ($data['parent']!=-1)
        {
            $sql = "select * from 'comments' where id = :id";
            $parent = $db->prepare($sql)->execute(['id' => $data['parent']])->fetch(); // 查找父评论

            // 被回复者需要有邮箱才可以回复 而且 不是回复自己的评论时才需要回复
            if ($parent['mail'] && $parent['mail'] != $data['mail'])
            {
                // 给被回复者发邮件
                $recipientMail = $parent['mail'];
                $recipientName = $parent['nick'];
                $recipientWebsite = $parent['website'];
            }
        } else { // 如果回复的是文章
            // 给博主发邮件
            $recipientMail = MAIL_OWNER_MAILS[0];
            $recipientName = MAIL_OWNER_NAME;
            $recipientWebsite = MAIL_SITE_URL;
        }

        // 如果有收件人的话就发送
        if ($recipientName && $recipientMail)
        {
            $sql = "select * from comments where time = :time and content = :content";
            $lastInsertion = $db->prepare($sql)->execute(['time' => $insertionTime, 'content' => $data['content']])->fetch();

            $permalink = MAIL_SITE_URL . $data['key'] . '#ac-comment-object-id-'.$lastInsertion['id'];

            $params = [
                'time' => time(),
                'content' => $data['content'],
                'author' => [
                    'name' => $data['nick'],
                    'mail' => $data['mail'],
                    'website' => $data['website']
                ],
                'recipient' => [
                    'name' => $recipientName,
                    'mail' => $recipientMail,
                    'website' => $recipientWebsite,
                ],
                'permalink' => $permalink,

                'subject' => MAIL_SUBJECT,
                'purpose' => '评论通知',
                'testMode' => MAIL_TEST_MODE,
            ];

            $params = CommentNotificationModel::FromArray($params);

            call_user_func_array($mailSystem->send, [$params]);
        }

        header('Content-Type:application/json;charset=utf-8');
        echo('[]'); // 避免JQ的DataType对不上导致无法执行success回调
    }

    public function getComments(array $params)
    {
        $db = self::getService('database');
        $pageCapacity = PAGE_CAPACITY;
        
        \Utils\Utils::checkMissingParameters2($_GET, ['key', 'label']);

        // 记录数据
        $cookieKey = 'viewed-page-'.md5($_GET['key']);
        if (!isset($_COOKIE[$cookieKey]) || $_COOKIE[$cookieKey] < time()) {
            $visitModel = new \Model\VisitModel($_GET['key'], $_GET['label'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
            call_user_func_array($this->container['analysis']['visit'], [$db, $visitModel]);
        }
        \Utils\Utils::setCookie($cookieKey, (string)(time() + PERIOD_AS_NEW_VISITOR), time() + PERIOD_AS_NEW_VISITOR);
        

        // 查询数据
        $key = $_GET['key'];
        $pagination = isset($_GET['pagination']) && $_GET['pagination']>=0? $_GET['pagination']:0;

        $sql = "select * from 'comments' where key = :key and parent = 0 order by time desc";
        $rows = $db->prepare($sql)->execute(['key' => $key])->fetchAll();

        $data = [];

        $commentCount = count($rows);
        $start = min($pagination * $pageCapacity, $commentCount);
        $end = min($start + $pageCapacity, $commentCount);

        for ($i=$start; $i<$end; $i++) {
            $row = $rows[$i];
            
            $avatarUrl = \Utils\Utils::getAvatarByMail($row['mail']);
            $isAuthor = in_array($row['mail'], MAIL_OWNER_MAILS);
            // 如果博主没有填写网站，则使用默认站点地址
            $website = !$isAuthor? $row['website']:($row['website']!=''? $row['website']:MAIL_SITE_URL);
            $content = \Smilie\SmilieSystem::showSmilies($row['content'], false);
            $replies = self::getRepliesOfComment($db, $row['id']);
            
            $data[] = [
                'id' => $row['id'],
                'avatar' => $avatarUrl,
                'nick' => $row['nick'],
                'website' => $website, 
                'isauthor' => $isAuthor,
                'authorlabel' => MAIL_OWNER_NAME,
                'useragent' => $row['useragent'],
                'content' => $content,
                'time' => $row['time'],
                'replies' => $replies,
            ];
        }

        header('Content-Type:application/json;charset=utf-8');
        echo(json_encode([
            'comments' => $data,
            'pages' => intval($commentCount / $pageCapacity) + ($commentCount % $pageCapacity>0? 1: 0),
            'count' => $commentCount
        ]));
    }

    private function getRepliesOfComment($db, $replyId)
    {
        $sql = "select * from 'comments' where parent = :parent order by time desc";
        $replies = $db->prepare($sql)->execute(['parent' => $replyId])->fetchAll();
        $repliesObj = [];
        foreach ($replies as $reply) {

            $avatarUrl = \Utils\Utils::getAvatarByMail($reply['mail']);
            $isAuthor = in_array($reply['mail'], MAIL_OWNER_MAILS);
            $content = \Smilie\SmilieSystem::showSmilies($reply['content'], false);
            $replies = self::getRepliesOfComment($db, $reply['id']);

            $repliesObj[] = [
                'id' => $reply['id'],
                'avatar' => $avatarUrl,
                'nick' => $reply['nick'],
                'website' => $reply['website'],
                'isauthor' => $isAuthor,
                'authorlabel' => MAIL_OWNER_NAME,
                'useragent' => $reply['useragent'],
                'content' => $content,
                'time' => $reply['time'],
                'replies' => $replies,
            ];
        }

        return $repliesObj;
    }

}

?>