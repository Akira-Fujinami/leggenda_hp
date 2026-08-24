<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 管理者ダッシュボード(/admin)の認証
    |--------------------------------------------------------------------------
    | DBにユーザーを作らない共有アカウント方式(依頼者指定)。username/
    | password_hashは.env(Renderでは環境変数)のみで管理し、コードに直接
    | 書かない。password_hashはHash::make()の出力(bcrypt)を想定 ―― 平文の
    | ADMIN_PASSWORDではなくADMIN_PASSWORD_HASHを保存する(万一.envが漏れた
    | 場合でも平文パスワードそのものは残らない)。
    */
    'username' => env('ADMIN_USERNAME'),
    'password_hash' => env('ADMIN_PASSWORD_HASH'),
];
