#!/bin/sh
FILE=app/CI
if [ -f "$FILE" ]; then
	PHPOPTIONS=""
	echo CI
else
	phprefdir=`eval echo "~"`
	phprefdir="$phprefdir/public_html"
	PHPOPTIONS="--ea-reference-dir=$phprefdir "
fi
FILE=vendor/bin/phpdoccheck
if [ -f "$FILE" ]; then
	echo "php $PHPOPTIONS $FILE --directory=./src/ -w"
	TEST=$(php $PHPOPTIONS $FILE --directory=./src/ -w)
fi

echo "$TEST"

if echo "$TEST" | grep -q "0 Errors" && echo "$TEST" | grep -q "0 Warnings"
then
  exit 0
fi

exit 1
