#!/bin/bash

# Declare error array
errors=0

# Iterate over all .tpl files in app directory
for template in `find ./app/skin/ -name '*.tpl'`
do
    # Count opening tags '{t}' and '{t '
    openTags=$(( `grep -o '{t}' $template | wc -l` + `grep -o '{t ' $template | wc -l` ))
    # Count closing tags '{/t}'
    closeTags=$((`grep -o '{/t}' $template | wc -l`))

    if [ $openTags != $closeTags ]
    then
        # Add file to error array
        errors=$(($errors + 1))
        # Echo the file
        echo 'Found error in ' $template
    fi

done

# Check if there are items in the error array
if [ $errors -eq 0 ]; then
    # Echo no errors message
    echo -e "\n\n\e[1;92m Found no errors \e[0m\n";
    # Exit code 0
    exit 0
else
    # Echo error message
    echo -e "\n\n\e[1;41m Found errors \e[0m\n";
    # Exit code 1
    exit 1
fi
