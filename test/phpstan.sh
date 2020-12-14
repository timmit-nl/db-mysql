#!/bin/sh
FILE=./CI
if [ -f "$FILE" ]; then
	PHPOPTIONS=""
	echo CI
else
	phprefdir=`eval echo "~"`
	phprefdir="$phprefdir/public_html"
	PHPOPTIONS="--ea-reference-dir=$phprefdir "
fi

FILE=vendor/bin/phpstan
if [ -f "$FILE" ]; then
	php $PHPOPTIONS $FILE analyse --memory-limit 768M
fi