#!/bin/sh
FILE=app/CI
if [ -f "$FILE" ]; then
	PHPOPTIONS=""
	echo CI
	PHPSTANOPTIONS="--memory-limit 2048M --configuration phpstan-strict.neon"
else
	phprefdir=`eval echo "~"`
	phprefdir="$phprefdir/public_html"
	PHPOPTIONS="--ea-reference-dir=$phprefdir "
	PHPSTANOPTIONS="--memory-limit 820M --configuration phpstan-strict.neon"
fi

FILE=vendor/bin/phpstan
if [ -f "$FILE" ]; then
	php $PHPOPTIONS $FILE clear-result-cache
	php $PHPOPTIONS $FILE analyse $PHPSTANOPTIONS
fi
