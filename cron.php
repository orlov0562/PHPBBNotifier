<?php

    class Cfg {
        const DB_HOST = 'localhost';
        const DB_USER = 'mysql';
        const DB_PASS = 'mysql';
        const DB_NAME = 'phpbb_forum';

        const EMAIL_FROM = 'Forum <no-reply@forum.site.com>';
        const EMAIL_REPLY_TO = 'no-reply@forum.site.com';
        const EMAIL_TO = 'admin@site.com';
        const EMAIL_SUBJECT = 'New posts at forum.site.com';

        const POSTS_PER_MAIL_LIMIT = 50; // per email limit

        const ADMIN_TIMEZONE_OFFSET = 3; // in hours from UTC

        const PHPBB_TBL_PREFIX = 'phpbb_';
        const PHPBB_BASE_URL = 'http://forum.site.com'; // base url of forum, ex: http://forum.site.com

        const ALLOW_IGNORE_USERS = true; // that option allow ignore messages from specified users (admins, moderators, etc..)

        public static function getIgnoreUsersSql() {
            $ignoreUsersWithEmails = [ // specify here emails that we should ignore
                'admin@site.com',
            ];

            if (!self::ALLOW_IGNORE_USERS OR empty($ignoreUsersWithEmails)) return '';

            return ' AND `users`.`user_email`!="'
                   .implode('" AND `users`.`user_email`!="', $ignoreUsersWithEmails)
                   .'"'
            ;
        }

        public static function getVar($var, $default=null) {
            $values = [];
            $storagePath = dirname(__FILE__).'/storage.json';
            if (file_exists($storagePath)) {
                $values = json_decode(file_get_contents($storagePath), true);
                if (!is_array($values)) $values =[];
            }
            return isset($values[$var]) ? $values[$var] : $default;
        }

        public static function setVar($var, $val) {
            $values = [];
            $storagePath = dirname(__FILE__).'/storage.json';
            if (file_exists($storagePath)) {
                $values = json_decode(file_get_contents($storagePath), true);
                if (!is_array($values)) $values =[];
            }
            $values[$var] = $val;
            file_put_contents($storagePath, json_encode($values));
        }

    }

    class DbConnection {
        private $host = '';
        private $user = '';
        private $pass = '';
        private $dbName = '';
        private $defaultCharset = 'utf8';
        private $dbLink = null;

        public function __construct($host, $user, $pass, $dbName) {
            $this->host = $host;
            $this->user = $user;
            $this->pass = $pass;
            $this->dbName = $dbName;
        }

        public function getConnection($force=false){
            if (is_null($this->dbLink) || $force) {
                $this->dbLink = new mysqli($this->host, $this->user, $this->pass, $this->dbName);

                if ($this->dbLink->connect_errno) {
                    echo "MySQL Error: Failed to make a MySQL connection, here is why: \n";
                    echo "Errno: " . $this->dbLink->connect_errno . "\n";
                    echo "Error: " . $this->dbLink->connect_error . "\n";
                    exit;
                }
            }

            if (!$this->dbLink->set_charset($this->defaultCharset)) {
                echo "MySQL Error loading character set $charset\n";
                echo "Errno: " . $this->dbLink->errno . "\n";
                echo "Error: " . $this->dbLink->error . "\n";
                exit;
            }

            return $this->dbLink;
        }

        public function setCharset($charset='utf8') {
            $db = $this->getConnection();
            if (!$db->set_charset($charset)){
                echo "MySQL Error loading character set $charset\n";
                echo "Errno: " . $db->errno . "\n";
                echo "Error: " . $db->error . "\n";
                exit;
            }
        }

        public function query($sql){
            $db = $this->getConnection();
            if (!$result = $db->query($sql)) {
                echo "MySQL Error: Our query failed to execute and here is why: \n";
                echo "Query: " . $sql . "\n";
                echo "Errno: " . $db->errno . "\n";
                echo "Error: " . $db->error . "\n";
                exit;
            }
            return $result;
        }

        public function select($sql){
            $result = $this->query($sql);
            $ret = [];
            if ($result->num_rows !== 0) {
                while ($row = $result->fetch_assoc()) {
                    $ret[] = $row;
                }
            }
            $result->free();
            return $ret;
        }

        public function close(){
            if (!is_null($this->dbLink)) {
                $this->dbLink->close();
            }
        }

        public function __destruct(){
            $this->close();
        }
    }

    $db = new DbConnection(Cfg::DB_HOST, Cfg::DB_USER, Cfg::DB_PASS, Cfg::DB_NAME);

    $lastSentPostId = Cfg::getVar('lastSentPostId',0);

    $sql = 'SELECT
                `posts`.`post_id` AS `post_id`,
                `posts`.`forum_id` AS `forum_id`,
                `posts`.`topic_id` AS `topic_id`,

                `posts`.`post_time` AS `time`,
                `posts`.`post_subject` AS `subject`,
                `posts`.`post_text` AS `text`,

                `users`.`username` AS `author`,
                `users`.`user_email` AS `email`
            FROM
                `'.Cfg::PHPBB_TBL_PREFIX.'posts` AS `posts`
            LEFT JOIN
                `'.Cfg::PHPBB_TBL_PREFIX.'users` AS `users`
            ON
                `posts`.`poster_id` = `users`.`user_id`
            WHERE
               `posts`.`post_id`>'.intval($lastSentPostId).'
               '.Cfg::getIgnoreUsersSql().'
            ORDER BY
                `posts`.`post_id` ASC
            LIMIT '.Cfg::POSTS_PER_MAIL_LIMIT.'
    ';

    $newPosts = $db->select($sql);

    if (!$newPosts) {
        echo 'No new posts from last check';
        exit;
    }

    $msgTemplate = '
    <html>
        <head>
            <style>
                div.text {
                    background-color: #F5F5F5;
                    font-size:13px;
                    margin:15px 0px;
                    max-width:600px;
                    border: 1px solid #D5D5D5;
                    border-left: 2px solid silver;
                    border-radius:5px;
                    padding:15px;
                }
                blockquote {
                    background-color: #d5d5d5;
                    border: 1px solid #c5c5c5;
                    padding: 5px;
                    color:black;
                    margin:0px 0px 0px 15px;
                    border-radius:2px;
                }
                blockquote blockquote {
                    background-color: #e4e4e4;
                    margin: 0.5em 1px 0 15px;
                }
                blockquote blockquote blockquote {
                    background-color: #f4f4f4;
                }
                .bbcode_tags {color:silver;}
            </style>
        </head>
        <body style="font-family:Courier; font-size:14px;">
            {body}
        </body>
    </html>
    ';

    $msgPostTemplate = '
        <strong>User</strong>: {author} &lt;{email}&gt;<br>
        <strong>Subject</strong>: {subject}<br>
        <strong>Date</strong>: {date}<br>
        <strong>Text</strong>:
            <div class="text">
                {text}
            </div>
        <strong>Manage</strong>: <a href="{forum_url}/viewtopic.php?f={forum_id}&t={topic_id}">Open topic</a><br>

        <br><hr><br>
    ';

    $msgBody = '';

    foreach($newPosts as $post) {

        $text = $post['text'];

        // replace quotes bbcode
        $text = preg_replace('~\[quote[^:\]]*:[^\]]+\]~','<blockquote>',$text);
        $text = preg_replace('~\[/quote:[^\]]+\]~','</blockquote>',$text);

        // replace code bbcode
        $text = preg_replace('~\[code[^:\]]*:[^\]]+\]~','<code>',$text);
        $text = preg_replace('~\[/code:[^\]]+\]~','</code>',$text);

        // replace b,i,u bbcodes
        $text = preg_replace('~\[(b|u|i):[^\]]+\]~','<$1>',$text);
        $text = preg_replace('~\[/(b|u|i):[^\]]+\]~','</$1>',$text);

        // replace urls bbcode
        $text = preg_replace('~\[url:[^\]]+\]([^\[]+)(?=\[)~','<a href="$1">$1',$text);
        $text = preg_replace('~\[url=([^:\]]+):[^\]]+\]([^\[]+)(?=\[)~','<a href="$1">$2',$text);
        $text = preg_replace('~\[/url:[^\]]+\]~','</a>',$text);

        // replace video bbcode
        $text = preg_replace('~\[video:[^\]]+\]([^\[]+)(?=\[)~','<a href="$1">$1',$text);
        $text = preg_replace('~\[/video:[^\]]+\]~','</a>',$text);

        // replace other bbcode
        $text = preg_replace('~\[([^:\]]+):[^\]]+\]~','<span class="bbcode_tags">[$1]</span>',$text);

        // clean up double spaces and double line endings. Convert line ending to new lines (br)

        // $text = preg_replace('~(\s)\s+~','$1',$text);
        $text = str_replace("\n",'<br>', trim($text));

        // Fix unclosed tags
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$text, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $text = $dom->saveHTML();

        $msgBody .=  str_replace(
            [
                '{author}',
                '{email}',
                '{subject}',
                '{date}',
                '{text}',
                '{forum_url}',
                '{forum_id}',
                '{topic_id}',
            ], [
                htmlspecialchars($post['author']),
                $post['email'] ? htmlspecialchars($post['email']) : 'undefined@email',
                $post['subject'],
                date('d.m.y H:i',strtotime('+'.Cfg::ADMIN_TIMEZONE_OFFSET.' hours',$post['time'])),
                $text,
                Cfg::PHPBB_BASE_URL,
                intval($post['forum_id']),
                intval($post['topic_id']),
            ],
            $msgPostTemplate
        );
    }

    $emailMessage = str_replace('{body}',$msgBody, $msgTemplate);

    $emailheaders = "From: " . Cfg::EMAIL_FROM . "\r\n";
    $emailheaders .= "Reply-To: ". Cfg::EMAIL_REPLY_TO . "\r\n";
    $emailheaders .= "MIME-Version: 1.0\r\n";
    $emailheaders .= "Content-Type: text/html; charset=UTF-8\r\n";

    if (mail(Cfg::EMAIL_TO, Cfg::EMAIL_SUBJECT, $emailMessage, $emailheaders)) {
        Cfg::setVar('lastSentPostId', $newPosts[count($newPosts)-1]['post_id']);
        echo 'Message about '.count($newPosts).' new post was succesfully sent'.PHP_EOL;
    } else {
        echo 'Error: Mail not sent'.PHP_EOL;
    }
