<?php

namespace Gini;

/*
   e.g.

   $mail = new \Gini\Mail;
   $mail
        ->from('jia.huang@geneegroup.com', 'Jia Huang')
        ->to('somebody@geneegroup.com', 'Somebody')
        ->subject('Hello, world!')
        ->body('lalalalalalala...')
        ->send();

 */

class Mail
{
    private $_recipients;
    private $_sender;
    private $_reply_to;
    private $_subject;
    private $_body;
    private $_header;
    private $_boundary;

    public function __construct($sender = null)
    {
        if (is_null($sender)) {
            $sender = (object) Config::get('system.postmaster');
        }

        $this->from($sender->email, $sender->name);
    }

    //创建header
    private function _makeHeader()
    {
        $header = (array) $this->_header;

        $header['User-Agent']='Genee-Q';
        $header['Date']=$this->_getDate();
        $header['X-Mailer']='Genee-Q';
        $header['X-Priority']='3 (Normal)';
        $header['Message-ID']=$this->_getMessageId();
        $header['Mime-Version']='1.0';
        if ($this->_boundary) {
            if ($this->_has_attachment) {
                $header['Content-Type'] = 'multipart/mixed; boundary='. $this->_boundary;
            } else {
                $header['Content-Type']='multipart/alternative; boundary='.$this->_boundary.'';
            }
        } else {
            //不存在boundary 说明只有plain
            //需要设定header中charset为utf-8
            //content-type为plain
            //encoding为base64

            if (!$this->_has_attachment) {
                $header['Content-Type'] = 'text/plain; charset=UTF-8';
                $header['Content-Transfer-Encoding'] = 'base64';
            }
        }

        $header_content = '';
        foreach ($header as $k=>$v) {
            $header_content .= "$k: $v\n";
        }

        //进行换行
        $header_content .= "\n";

        return $header_content;
    }

    private $_body_plain, $_body_html;

    //创建body
    private function _makeBody()
    {
        $_body_plain = $this->_bodyPlain();

        if ($_body_plain) {
            if ($this->_boundary) $_body .= "--{$this->_boundary}\n";
            $_body .= $_body_plain;
        }

        $_body_html = $this->_bodyHTML();

        if ($_body_html) {
            $_body .= $_body_html;
        }

        $_body_attachment = $this->_bodyAttachment();

        if ($_body_attachment) {
            $_body .= $_body_attachment;
        }

        //body结束后补充boundary
        if ($this->_boundary) $_body .= "--{$this->_boundary}--";

        return $_body;
    }

    //获取plain内容相关body
    //body_plain有可能只设定plain
    private function _bodyPlain()
    {
        if ($this->_has_attachment) return false;

        if ($this->_boundary) {
            $_body_plain = array();
            $_body_plain[] = 'Content-Type: text/plain; charset=UTF-8';
            $_body_plain[] = 'Content-Transfer-Encoding: base64';
            $_body_plain[] = null;
            $_body_plain[] = chunk_split(base64_encode($this->_body_plain));
            $_body_plain[] = null;

            return join("\n", $_body_plain);
        } else {
            return chunk_split(base64_encode($this->_body_plain));
        }
    }

    //获取html内容相关body
    private function _bodyHTML()
    {
        if (!$this->_body_html && !$this->_has_attachment) return false;

        $_body_html = array();
        $_body_html[] = "--{$this->_boundary}";
        $_body_html[] = 'Content-Type: text/html; charset=UTF-8';
        $_body_html[] = 'Content-Transfer-Encoding: base64';
        $_body_html[] = null;

        //存在attachment但是不存在html, 解析plain为html, 增加换行
        if ($this->_has_attachment) $this->_body_html = ($this->_body_html ? : $this->_body_plain). '<br />';

        $_body_html[] = chunk_split(base64_encode($this->_body_html));
        $_body_html[] = null;

        return join("\n", $_body_html);
    }

    //获取附件相关body
    private function _bodyAttachment()
    {
        if (!$this->_has_attachment) return null;

        foreach ($this->_attachments as $path => $file) {
            $attach_data[] = sprintf('--%s', $this->_boundary);
            $attach_data[] = sprintf('Content-Disposition: attachment; filename="%s"', $file);
            $attach_data[] = sprintf('Content-Type: %s; name="%s"',  File::mimeType($path) ? : 'application/octet-stream', $file);
            $attach_data[] = 'Content-Transfer-Encoding: base64';
            $attach_data[] = null; //需要占位，这样mail发送才能正常进行解析附件
            $attach_data[] = chunk_split(@base64_encode(@file_get_contents($path)));
            $attach_data[] = null;
        }

        return join("\n", $attach_data);
    }

    private function _encodeText($text)
    {
        return mb_encode_mimeheader($text, 'UTF-8');
    }

    //进行发送
    public function send()
    {
        $subject = $this->_encodeText($this->_subject);

        $recipients = $this->_header['To'];
        unset($this->_header['To']);

        $header = $this->_makeHeader();
        $body = $this->_makeBody();

        $success = mail($recipients, $subject, $body, $header);

        $subject = $this->_subject;
        $recipients =  $this->_recipients;
        $sender = $this->_sender;
        $success = $success ? 'OK' : 'ERR';

        Logger::of('mail')->error("[{$success}] {$sender} => {$recipients}: {$subject}");

        return $success;
    }

    //设定当前发送人员信息
    public function from($email, $name = null)
    {
        $this->_header['From'] = $name ? $this->_encodeText($name) . "<$email>" : $email;
        $this->_header['Return-Path']="<$email>";
        $this->_header['X-Sender']=$email;

        $this->_sender = $email;

        return $this;
    }

    //设定回复地址
    public function replyTo($email, $name = null)
    {
        $this->_header['Reply-To'] = $name ? $this->_encodeText($name) . "<$email>" : $email;

        $this->_reply_to = $email;

        return $this;
    }

    //接收目标设定
    public function to($email, $name = null)
    {
        if (is_array($email)) {
            $mails = array();
            $header_to = array();
            foreach ($email as $k=>$v) {
                if (is_numeric($k)) {
                    $mails[] = $v;
                    $header_to[] = $v;
                } else {
                    // $k是email, $v是name
                    $mails[] = $k;
                    $header_to[] = $v ? $this->_encodeText($v) . "<$k>" : $k;
                }
            }
            $this->_header['To'] = implode(', ', $header_to);
            $this->_recipients = implode(', ', $mails);
        }
        else {
            $this->_header['To'] = $name ? $this->_encodeText($name) . "<$email>" : $email;
            $this->_recipients = $email;
        }

        return $this;
    }

    //邮件标题
    public function subject($subject)
    {
        $subject = preg_replace("/(\r\n)|(\r)|(\n)/", "", $subject);
        $subject = preg_replace("/(\t)/", " ", $subject);

        $this->_subject = trim($subject);

        return $this;
    }

    //邮件正文设定
    public function body($plain, $html = null)
    {
        if (!$html) {
            $this->_boundary = null;
            $this->_body_plain = $plain;
        } else {
            $this->_makeBoundary();
            $this->_body_plain = $plain;
            $this->_body_html = $html;
        }

        return $this;
    }

    //创建Boundary
    private function _makeBoundary()
    {
        $this->_boundary = $this->_boundary ? : 'GENEE-'.md5(time());
    }

    private function _getMessageId()
    {
        $from = $this->_headers['Return-Path'];
        $from = str_replace('>', '', $from);
        $from = str_replace('<', '', $from);

        return  '<'.uniqid('').strstr($from, '@').'>';
    }

    private function _getDate()
    {
        $timezone = date("Z");
        $operator = (substr($timezone, 0, 1) == '-') ? '-' : '+';
        $timezone = abs($timezone);
        $timezone = floor($timezone/3600) * 100 + ($timezone % 3600 ) / 60;

        return sprintf('%s %s%04d', date('D, j M Y H:i:s'), $operator, $timezone);
    }

    //存储所有附件
    private $_attachments = array();
    private $_has_attachment;

    //邮件中增加附件功能
    public function attachment($files = '')
    {
        if (!is_array($files)) $files = array($files);

        foreach ($files as $file) {
            //增加到attachment中
            $this->_attachments[$file] = basename($file);
        }

        $this->_has_attachment = (bool) count($this->_attachments);

        $this->_makeBoundary();

        return $this;
    }
}
