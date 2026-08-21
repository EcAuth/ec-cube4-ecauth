<?php

/**
 * EC-CUBE 4.0 系 (Composer v1) の composer.json を検証環境向けに調整する。
 *
 * packagist.org は 2025-08-01 に Composer v1 メタデータの提供を終了した。
 * EC-CUBE 4.0 系はプラグインの install / enable / disable / uninstall / update で
 * composer を経由することがあり、素の composer.json のままだと依存解決が
 * "no matching package found" で落ちる。
 *
 * 対応内容は EC-CUBE 公式ドキュメントに従う。
 * https://doc4.ec-cube.net/plugin_eccube40
 *
 *   1. require-dev を削除する (開発時にしか使わず、依存解決の巻き添えを生むだけ)
 *   2. packagist.org を無効化する
 *   3. ec-cube/plugin-installer を GitHub の vcs リポジトリから引く
 *
 * このスクリプトが書き換えるのは EC-CUBE 本体の composer.json であって、
 * プラグイン側のコードには一切触れない。「コア改変」に当たるのではという懸念に対しては、
 * 公式ドキュメントが 4.0 系の利用者に案内している手順そのものである、というのが答え。
 */
$path = isset($argv[1]) ? $argv[1] : '';

if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "usage: fix-composer-v1.php <path/to/composer.json>\n");
    exit(1);
}

$json = json_decode(file_get_contents($path), true);

if (!is_array($json)) {
    fwrite(STDERR, "composer.json を JSON として読めません: {$path}\n");
    exit(1);
}

unset($json['require-dev']);

// repositories はオブジェクト形式 (名前付き) と配列形式の両方がありうる。
// EC-CUBE 4.0.6-p5 の配布パッケージには repositories 自体が無いので通常は新規作成になる。
// 配列形式で来た場合は名前で無効化できないため、その旨を報せて手当てを促す。
$repositories = isset($json['repositories']) ? $json['repositories'] : [];

if ($repositories !== [] && array_keys($repositories) === range(0, count($repositories) - 1)) {
    fwrite(STDERR, "repositories が配列形式です。packagist.org の無効化は名前付き形式でないと効きません\n");
    exit(1);
}

$repositories['packagist.org'] = false;
$repositories['eccube/plugin-installer'] = [
    'type' => 'vcs',
    'url' => 'https://github.com/EC-CUBE/eccube-plugin-installer',
    'no-api' => true,
];

$json['repositories'] = $repositories;

$encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($encoded === false) {
    fwrite(STDERR, "composer.json の書き出しに失敗しました\n");
    exit(1);
}

file_put_contents($path, $encoded."\n");

fwrite(STDOUT, "Composer v1 対応を適用しました (require-dev 削除 / packagist.org 無効化 / plugin-installer を vcs 参照)\n");
