#!/usr/bin/env bash
#
# ownCloud Music
#
# @author Pauli Järvinen
# @copyright 2025, 2026 Pauli Järvinen <pauli.jarvinen@gmail.com>
#

if [ "$#" -ne 2 ]; then
    echo "Usage: $0 <owncloud|nextcloud> <cloud_version>"
    exit 1
fi

CLOUD=$1
VERSION=$2

mkdir -p /tmp/oc_music_ci
cd /tmp/oc_music_ci

# download the cloud and setup folders
if [ $CLOUD == 'owncloud' ]; then
    if [[ $VERSION == *"daily-master"* ]]; then
        URL=https://download.owncloud.com/server/daily
    else
        URL=https://download.owncloud.com/server/stable
    fi
elif [[ $VERSION == *"beta"* || $VERSION == *"rc"* ]]; then
    URL=https://download.nextcloud.com/server/prereleases
else
    URL=https://download.nextcloud.com/server/releases
fi

wget $URL/$CLOUD-$VERSION.zip
unzip $CLOUD-$VERSION.zip
mv $CLOUD server
