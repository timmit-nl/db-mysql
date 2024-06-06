#!/bin/bash

# Declare error array
errors=0

# Iterate over all .php files in app directory
for file in `find ./src/ -name '*.php'`
do
    # Find html tags (excluded <pre>, <br>, <b>)
    hits=$(( `grep -R -e "<blockquote" -e "<body" -e "<button" -e "<caption" -e "<col" -e "<colgroup" -e "<dd" -e "<del" -e "<dfn" -e "<div" -e "<dl" -e "<dt" -e "<em" -e "<footer" -e "<form" -e "<h1" -e "<h2" -e "<h3" -e "<h4" -e "<h5" -e "<h6" -e "<head" -e "<hr" -e "<html" -e "<i" -e "<img" -e "<input" -e "<label" -e "<li" -e "<link" -e "<nav" -e "<noscript" -e "<object" -e "<ol" -e "<option" -e "<p>" -e "<select" -e "<small" -e "<span" -e "<strong" -e "<style" -e "<sub" -e "<table" -e "<tbody" -e "<td" -e "<textarea" -e "<tfoot" -e "<th" -e "<thead" -e "<title" -e "<tr" -e "<u" -e "<ul" $file | wc -l`))

    if [ $hits != 0 ]
    then
        # Add file to error array
        errors=$(($errors + 1))
        # Echo the file
        echo 'Forbidden html usage in ' $file
    fi

done

# Check if there are items in the error array
if [ $errors -eq 0 ]; then
    # Echo no errors message
    echo "\n\n\e[1;92m Found no errors \e[0m\n";
    # Exit code 0
    exit 0
else
    # Echo error message
    echo "\n\n\e[1;41m Found errors \e[0m\n";
    # Exit code 1
    exit 1
fi
