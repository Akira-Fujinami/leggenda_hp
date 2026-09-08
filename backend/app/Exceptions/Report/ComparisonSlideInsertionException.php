<?php

namespace App\Exceptions\Report;

/**
 * 依頼BG: 多社比較スライドを営業資料(PPTX)へ差し込めなかった「想定内の
 * 中止」を表す例外(参照元ページが見つからない・スライドサイズが一致しない
 * ・添付が無い/PPTXでない、等)。メッセージは管理画面にそのまま表示できる
 * 想定のため、内部パス・添付ファイルの中身は含めない。
 */
class ComparisonSlideInsertionException extends \RuntimeException {}
