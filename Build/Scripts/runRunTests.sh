#!/usr/bin/env bash

# Run the runTests.sh script with different environment configurations, somewhat like the github workflow matrix.
# Most likely only runs under linux. Has the same requirements like the runTests script: docker and bash.

SCRIPT_DIR="$( cd "$(dirname "$0")" >/dev/null 2>&1 ; pwd -P )"
PROJECT_ROOT=$(realpath "${SCRIPT_DIR}/../../")

PHP_VERSIONS=(
    '8.2'
    '8.3'
    '8.4'
    '8.5'
)

TYPO3_VERSIONS=(
    'typo3/cms-core:^12.4'
    'typo3/cms-core:^13.4'
)

DBMS_VARIANTS=(
    '-d mariadb -i 10.4'
    '-d mariadb -i 10.11'
    '-d mysql -i 8.0'
    '-d mysql -i 8.4'
    '-d postgres -i 13'
    '-d postgres -i 14'
    '-d postgres -i 15'
    '-d postgres -i 16'
)

for typo3Version in "${TYPO3_VERSIONS[@]}"; do
    for phpVersion in "${PHP_VERSIONS[@]}"; do
        for dbmsVariant in "${DBMS_VARIANTS[@]}"; do
            "${PROJECT_ROOT}/Build/Scripts/runTests.sh" ${dbmsVariant} -p "${phpVersion}" -s composer -- update --with "${typo3Version}"
            "${PROJECT_ROOT}/Build/Scripts/runTests.sh" ${dbmsVariant} -p "${phpVersion}" -s functional

            TEST_EXIT_CODE=$?
            if [ ${TEST_EXIT_CODE} -ne 0 ]
                then
                    echo "Testing scenario failed! Terminating early..."
                    echo "Executed commands:"
                    echo "${PROJECT_ROOT}/Build/Scripts/runTests.sh" ${dbmsVariant} -p "${phpVersion}" -s composer -- update --with "${typo3Version}"
                    echo "${PROJECT_ROOT}/Build/Scripts/runTests.sh" ${dbmsVariant} -p "${phpVersion}" -s functional
                    exit 1
                else
                    # Small delay for the user to check the result
                    sleep 3
            fi;
        done;
    done;
done;
