<?php

/**
 * XSS対策：画面に出力する文字列をエスケープする。
 * 値を表示するときは必ずこの h() を通すこと。
 */
function h($s)
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
