#!/bin/bash

## Delete/create a dir
## usage: dm_reset_dirs <path1> <path2> ...
function dm_reset_dirs() {
  for d in "$@" ; do
    [ -d "$d" ] && rm -rf "$d"
  done

  mkdir -p "$@"
}

## Assert that a folder contains no symlinks
##
## ex: dev/core#1393, dev/core#1990
## usage: dm_assert_no_symlinks <basedir>
function dm_assert_no_symlinks() {
  local SYMLINKS=$( find "$1" -type l )
  if [ -n "$SYMLINKS" ]; then
    echo "ERROR: Folder $1 contains unexpected symlink(s): $SYMLINKS"
    exit 10
  fi
}

## Copy files from one dir into another dir
## usage: dm_install_dir <from-dir> <to-dir>
function dm_install_dir() {
  local from="$1"
  local to="$2"

  if [ ! -d "$to" ]; then
    mkdir -p "$to"
  fi
  ${DM_RSYNC:-rsync} -avC --exclude=.git --exclude=.svn "$from/./"  "$to/./"
}

## Copy listed files
## usage: dm_install_files <from-dir> <to-dir> <file1> <file2>...
function dm_install_files() {
  local from="$1"
  shift
  local to="$1"
  shift

  for file in "$@" ; do
    [ -f "$from/$file" ] && cp -f "$from/$file" "$to/$file"
  done
}

## usage: dm_remove_files <directory> <file1> <file2>...
function dm_remove_files() {
  local tgt="$1"
  shift

  for file in "$@" ; do
    [ -f "$tgt/$file" -o -L "$tgt/$file" ] && rm -f "$tgt/$file"
  done
}

## Copy all bower dependencies
function dm_install_bower() {
  local repo="$1"
  local to="$2"

  local excludes_rsync=""
  for exclude in .git .svn {T,t}est{,s} {D,d}oc{,s} {E,e}xample{,s} ; do
    excludes_rsync="--exclude=${exclude} ${excludes_rsync}"
  done

  [ ! -d "$to" ] && mkdir "$to"
  ${DM_RSYNC:-rsync} -avC $excludes_rsync "$repo/./" "$to/./"
}

## Copy all core files
## usage: dm_install_core <core_repo_path> <to_path>
function dm_install_core() {
  local repo="$1"
  local to="$2"

  for dir in ang css i js PEAR templates bin CRM api extern Reports install mixin settings Civi partials release-notes xml setup ; do
    [ -d "$repo/$dir" ] && dm_install_dir "$repo/$dir" "$to/$dir"
  done

  dm_install_files "$repo" "$to" {agpl-3.0,agpl-3.0.exception,gpl,CONTRIBUTORS}.txt
  dm_install_files "$repo" "$to" composer.json composer.lock package.json Civi.php README.md release-notes.md extension-compatibility.json guzzle_php81_shim.php

  mkdir -p "$to/sql"
  pushd "$repo" >> /dev/null
    dm_install_files "$repo" "$to" sql/civicrm*.mysql sql/case_sample*.mysql
    ## TODO: for master, remove counties.US.SQL.gz
  popd >> /dev/null

  if [ -d $to/bin ] ; then
    rm -f $to/bin/setup.sh
    rm -f $to/bin/setup.php4.sh
    rm -f $to/bin/setup.bat
  fi

  set +e
  rm -rf $to/sql/civicrm_*.??_??.mysql
  rm -rf $to/mixin/*/example
  set -e
}

## Copy built-in extensions
## usage: dm_install_core <core_repo_path> <to_path> <ext-dirs...>
function dm_install_coreext() {
  local repo="$1"
  local to="$2"
  shift
  shift

  for relext in "$@" ; do
    [ ! -d "$to/ext/$relext" ] && mkdir -p "$to/ext/$relext"
    ${DM_RSYNC:-rsync} -avC $excludes_rsync --include=core "$repo/ext/$relext/./" "$to/ext/$relext/./"
  done
}

## Get a list of default/core extension directories (space-delimited)
## reldirs=$(dm_core_exts)
function dm_core_exts() {
  ## grep to exclude comments and blank lines
  grep '^[a-zA-Z]' "$DM_SOURCEDIR"/distmaker/core-ext.txt
}

## Copy all packages
## usage: dm_install_packages <packages_repo_path> <to_path>
function dm_install_packages() {
  local repo="$1"
  local to="$2"

  local excludes_rsync=""
  for exclude in .git .svn _ORIGINAL_ SeleniumRC PHPUnit PhpDocumentor SymfonyComponents git-footnote PHP/CodeCoverage ; do
    excludes_rsync="--exclude=${exclude} ${excludes_rsync}"
  done

  ## Note: These small folders have items that previously were not published,
  ## but there's no real cost to including them, and excluding them seems
  ## likely to cause confusion as the codebase evolves:
  ##   packages/Files packages/PHP packages/Text

  [ ! -d "$to" ] && mkdir "$to"
  ${DM_RSYNC:-rsync} -avC $excludes_rsync --include=core "$repo/./" "$to/./"
}

## Copy Drupal-integration module
## usage: dm_install_drupal <drupal_repo_path> <to_path>
function dm_install_drupal() {
  local repo="$1"
  local to="$2"
  dm_install_dir "$repo" "$to"

  # Set full version in .info files. See CRM-15768.
  local MODULE_DIRS=`find "$to" -type f -name "*.info"`
  for INFO in $MODULE_DIRS; do
    dm_preg_edit '/version = ([0-9]*\.x)-[1-9.]*/m' "version = \$1-$DM_VERSION" "$INFO"
  done

  for f in "$to/.gitignore" "$to/.toxic.json" ; do
    if [ -f "$f" ]; then
      rm -f "$f"
    fi
  done
}

## Copy Joomla-integration module
## usage: dm_install_joomla <joomla_repo_path> <to_path>
function dm_install_joomla() {
  local repo="$1"
  local to="$2"
  dm_install_dir "$repo" "$to"

  ## Before this change, the zip file included the joomla-integration
  ## modules twice. The two were basically identical -- except that
  ## one included .gitignore and the omitted it. We'll now omit it
  ## consistently.

  for f in "$to/.gitignore" "$to/.toxic.json" ; do
    if [ -f "$f" ]; then
      rm -f "$f"
    fi
  done
}

## usage: dm_install_l10n <l10n_repo_path> <to_path>
function dm_install_l10n() {
  local repo="$1"
  local to="$2"
  dm_install_dir "$repo" "$to"
}

## Copy composer's "vendor" folder
## usage: dm_install_vendor <from_path> <to_path>
function dm_install_vendor() {
  local repo="$1"
  local to="$2"

  local excludes_rsync=""
  ## CRM-21729 - .idea test-cases unit-test come from phpquery package.
  for exclude in .git .svn {T,t}est{,s} {D,d}oc{,s} {E,e}xample{,s} .idea test-cases unit-test README.rst; do
    excludes_rsync="--exclude=${exclude} ${excludes_rsync}"
  done

  [ ! -d "$to" ] && mkdir "$to"
  ${DM_RSYNC:-rsync} -avC $excludes_rsync "$repo/./" "$to/./"
  ## We don't this use CLI script in production, and the symlink breaks D7/BD URL installs
  dm_remove_files "$to" "bin/pscss" "bin/cssmin"
}

##  usage: dm_install_wordpress <wp_repo_path> <to_path>
function dm_install_wordpress() {
  local repo="$1"
  local to="$2"

  if [ ! -d "$to" ]; then
    mkdir -p "$to"
  fi
  ${DM_RSYNC:-rsync} -avC \
    --exclude=.git \
    --exclude=.svn \
    --exclude=civicrm.config.php.wordpress \
    --exclude=.toxic.json \
    --exclude=.gitignore \
    --exclude=civicrm \
    "$repo/./"  "$to/./"
  ## Need --exclude=civicrm for self-building on WP site

  dm_preg_edit '/^([ \*]*)Version: [0-9\.]+/m' "\1Version: $DM_VERSION" "$to/civicrm.php"
  dm_preg_edit "/^define\( *\'CIVICRM_PLUGIN_VERSION\', *'[0-9\.]+/m" "define('CIVICRM_PLUGIN_VERSION', '$DM_VERSION" "$to/civicrm.php"
}

## Generate the composer "vendor" folder
## usage: dm_generate_vendor <repo_path>
function dm_generate_vendor() {
  local repo="$1"
  pushd "$repo"
    ${DM_COMPOSER:-composer} install
  popd
}

## Generate civicrm-version.php
## usage: dm_generate_version <file> <ufname>
function dm_generate_version() {
  local to="$1"
  local ufname="$2"

  # final touch
  echo "<?php
/** @deprecated */
function civicrmVersion( ) {
  return array( 'version'  => '$DM_VERSION',
                'cms'      => '$ufname',
                'revision' => '$DM_REVISION' );
}
" > "$to"
}

## Perform a hard checkout on a given report
## usage: dm_git_checkout <repo_path> <tree-ish>
function dm_git_checkout() {
  pushd "$1"
    git checkout .
    git checkout "$2"
  popd
}

## Download a Civi extension
## usage: dm_install_cvext <full-ext-key> <target-path>
function dm_install_cvext() {
  # cv dl -b '@https://civicrm.org/extdir/ver=4.7.25|cms=Drupal/com.iatspayments.civicrm.xml' --destination=$PWD/iatspayments
  cv dl -b "@https://civicrm.org/extdir/ver=$DM_VERSION|cms=Drupal/$1.xml" --to="$2"
}

## Export a list of patch files from a git repo
## usage: dm_export_patches <src-repo> <out-dir> <range>
## ex: dm_export_patches "$HOME/src/somerepo" "/tmp/export" 5.1.2..5.1.6
function dm_export_patches() {
  if [ ! -d "$1" ]; then
    echo "ignore: $1"
    return
  fi
  echo "Export \"$1\" ($3) to \"$2\""
  pushd "$1" >> /dev/null
    git format-patch "$3" -o "$2"
  popd >> /dev/null
}


## Edit a file by applying a regular expression.
## Note: We'd rather just call "sed", but it differs on GNU+BSD.
## usage: dm_preg_edit <search-pattern> <replacement-pattern> <file>
## example: '/version = \([0-9]*\.x-\)[1-9.]*/' 'version = \1$DM_VERSION'
function dm_preg_edit() {
  env RPAT="$1" RREPL="$2" RFILE="$3" \
    php -r '$c = file_get_contents(getenv("RFILE")); $c = preg_replace(getenv("RPAT"), getenv("RREPL"), $c); file_put_contents(getenv("RFILE"), $c);'
}
