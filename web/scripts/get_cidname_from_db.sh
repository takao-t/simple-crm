#!/bin/sh
DBFILE="/var/www/html/crm/db/crm_db.sqlite3"

if [ -z "$1" ]; then
    exit 1
else
    QPHONE="'""$1""'"
    sqlite3 $DBFILE "SELECT last_name || first_name FROM customers WHERE REPLACE(phone, '-', '') = REPLACE($QPHONE, '-', '');" -separator '' -noheader -batch
fi
