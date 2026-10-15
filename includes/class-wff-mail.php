<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Single visual system for Finance transactional email. Keep styles inline for
 * Outlook/Gmail support; never include financial records in invitation emails.
 */
final class WFF_Mail {
    private static function layout($eyebrow, $title, $message, $cta_label, $cta_url, $detail = '') {
        $title = esc_html($title);
        $message = esc_html($message);
        $detail = esc_html($detail);
        $url = esc_url($cta_url);
        $button = $url ? '<tr><td style="padding:28px 0 16px">'
            . '<a href="' . $url . '" style="display:inline-block;background:#000;color:#fff;text-decoration:none;font-size:15px;'
            . 'line-height:22px;font-weight:bold;padding:15px 26px;border:1px solid #000">' . esc_html($cta_label) . ' &rarr;</a>'
            . '</td></tr>' : '';
        return '<!doctype html><html lang="zh-Hant"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $title . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f5f5f5;color:#000;font-family:Arial,'
            . '&quot;Microsoft JhengHei&quot;,sans-serif">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f5f5f5">'
            . '<tr><td align="center" style="padding:35px 14px">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" '
            . 'style="max-width:560px;background:#fff;border:1px solid #000">'
            . '<tr><td style="padding:32px 30px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            . '<tr><td style="font:12px/20px monospace;letter-spacing:2px;color:#555">'
            . 'WUMETAX / FINANCE</td></tr>'
            . '<tr><td style="font:12px/20px monospace;letter-spacing:1px;padding-top:19px">'
            . esc_html($eyebrow) . '</td></tr>'
            . '<tr><td style="font-size:30px;font-weight:bold;line-height:1.35;padding:13px 0 18px;'
            . 'border-bottom:2px solid #000">' . $title . '</td></tr>'
            . '<tr><td style="font-size:15px;line-height:1.9;padding-top:20px;white-space:pre-line">' . $message . '</td></tr>'
            . ($detail ? '<tr><td style="font-size:13px;line-height:1.75;padding:15px;'
                . 'background:#f5f5f5;border:1px solid #000;margin-top:16px">' . $detail . '</td></tr>' : '')
            . $button
            . '<tr><td style="font-size:12px;line-height:1.8;color:#555;padding-top:8px">'
            . '如果按鈕無法開啟，可複製以下網址貼到瀏覽器：<br>'
            . '<a href="' . $url . '" style="color:#000;word-break:break-all">' . esc_html($cta_url) . '</a></td></tr>'
            . '<tr><td style="font-size:11px;line-height:1.8;color:#666;border-top:1px solid #ddd;'
            . 'padding-top:19px;margin-top:22px">'
            . '此通知由 WUMETAX Finance 自動寄出；如非本人操作，請忽略此信。'
            . '<br>財務資訊僅保存在所屬 WordPress 網站，不會附加在郵件中。</td></tr>'
            . '</table></td></tr></table></td></tr></table></body></html>';
    }

    private static function send($to, $subject, $html) {
        return (bool) wp_mail($to, $subject, $html, array('Content-Type: text/html; charset=UTF-8'));
    }

    public static function invitation($email, $household_name, $role, $link) {
        $permission = $role === 'editor' ? '可共同記帳及編輯' : '可查看家庭與公司收支';
        $content = '你收到「' . $household_name . '」的家庭財務管理邀請。'
            . "\n\n" . '加入後可共同查看家庭與公司收支，權限為「' . $permission . '」。'
            . "\n" . '邀請連結於 7 天後失效，且僅限指定 Email 使用。';
        return self::send($email, '【Finance】加入 ' . $household_name . ' 的家庭空間',
            self::layout('FAMILY INVITATION', '邀請加入家庭', $content, '查看邀請並加入家庭', $link,
                '受邀電子郵件：' . $email . '　｜　權限：' . $permission));
    }

    public static function password_reset($user, $url) {
        $content = '我們收到你在 WUMETAX Finance 提出的密碼重設申請。'
            . "\n\n" . '請點擊下方按鈕設定新密碼。如果你沒有提出申請，可以直接忽略這封信。';
        return self::send($user->user_email, '【Finance】重設你的登入密碼',
            self::layout('ACCOUNT SECURITY', '重設登入密碼', $content, '設定新密碼', $url,
                '帳號：' . $user->user_login . '。連結僅限一次使用，逾期請重新申請。'));
    }

    public static function registration($user, $url) {
        return self::send($user->user_email, '【Finance】設定密碼並啟用帳號',
            self::layout('WELCOME TO FINANCE', '歡迎加入 Finance',
                '你的免費帳號已建立，請先設定密碼，之後就能登入並建立自己的家庭財務空間。',
                '設定密碼並開始使用', $url, '帳號：' . $user->user_login . '。完全無須訂閱。'));
    }
}
