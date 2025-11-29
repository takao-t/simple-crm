〇 CIDnameをCRMから取得するためのスクリプト

php get_cidname_from_db.php 電話番号

標準出力にCRMから取得したCID名が出力される。DBへのアクセス情報はスクリプト内で設定のこと。

Asteriskのextenからの使用例

exten => s,n,Set(CIDNAME=${SHELL(php /var/lib/asterisk/scripts/get_cidname_from_db.php ${CALLERID(num)})})

標準出力から得られるのでSHELL functionで実行し、結果を変数に入れる。
