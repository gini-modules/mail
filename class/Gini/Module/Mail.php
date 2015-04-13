<?php

namespace Gini\Module;

class Mail
{
    public static function diagnose()
    {
        // 建议设置默认的邮件发送人
        // 因为mail提供了这个功能
        $sender = (object) \Gini\Config::get('system.postmaster');
        if (!$sender->email || !$sender->name) {
            return ['Please config your default mail sender (postmaster.email & postmaster.name) in system.yml!'];
        }
    }
}

