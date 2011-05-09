<?php

/*
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

class PhabricatorJavelinLinter extends ArcanistLinter {

  private $symbols = array();

  const LINT_PRIVATE_ACCESS = 1;
  const LINT_MISSING_DEPENDENCY = 2;
  const LINT_UNNECESSARY_DEPENDENCY = 3;
  const LINT_UNKNOWN_DEPENDENCY = 4;

  public function willLintPaths(array $paths) {
    $futures = array();
    foreach ($paths as $path) {
      $future = $this->newSymbolsFuture($path);
      $futures[$path] = $future;
    }

    foreach (Futures($futures)->limit(8) as $path => $future) {
      $this->symbols[$path] = $future->resolvex();
    }
  }

  public function getLinterName() {
    return 'JAVELIN';
  }

  public function getLintSeverityMap() {
    return array();
  }

  public function getLintNameMap() {
    return array(
      self::LINT_PRIVATE_ACCESS => 'Private Method/Member Access',
      self::LINT_MISSING_DEPENDENCY => 'Missing Javelin Dependency',
      self::LINT_UNNECESSARY_DEPENDENCY => 'Unnecessary Javelin Dependency',
      self::LINT_UNKNOWN_DEPENDENCY => 'Unknown Javelin Dependency',
    );
  }

  public function lintPath($path) {

    list($uses, $installs) = $this->getUsedAndInstalledSymbolsForPath($path);
    foreach ($uses as $symbol => $line) {
      $parts = explode('.', $symbol);
      foreach ($parts as $part) {
        if ($part[0] == '_' && $part[1] != '_') {
          $base = implode('.', array_slice($parts, 0, 2));
          if (!array_key_exists($base, $installs)) {
            $this->raiseLintAtLine(
              $line,
              0,
              self::LINT_PRIVATE_ACCESS,
              "This file accesses private symbol '{$symbol}' across file ".
              "boundaries. You may only access private members and methods ".
              "from the file where they are defined.");
          }
          break;
        }
      }
    }

    if ($this->getEngine()->getCommitHookMode()) {
      // Don't do the dependency checks in commit-hook mode because we won't
      // have an available working copy.
      return;
    }

    $external_classes = array();
    foreach ($uses as $symbol => $line) {
      $parts = explode('.', $symbol);
      $class = implode('.', array_slice($parts, 0, 2));
      if (!array_key_exists($class, $external_classes) &&
          !array_key_exists($class, $installs)) {
        $external_classes[$class] = $line;
      }
    }

    $celerity = CelerityResourceMap::getInstance();

    $path = preg_replace(
      '@^externals/javelin/src/@',
      'webroot/rsrc/js/javelin/',
      $path);
    $info = $celerity->lookupFileInformation(substr($path, strlen('webroot')));

    $need = $external_classes;

    $requires = $info['requires'];

    foreach ($requires as $key => $name) {
      $symbol_info = $celerity->lookupSymbolInformation($name);
      if (!$symbol_info) {
        $this->raiseLintAtLine(
          0,
          0,
          self::LINT_UNKNOWN_DEPENDENCY,
          "This file @requires component '{$name}', but it does not ".
          "exist. You may need to rebuild the Celerity map.");
        unset($requires[$key]);
        continue;
      }

      $symbol_path = 'webroot'.$symbol_info['disk'];
      list($ignored, $req_install) = $this->getUsedAndInstalledSymbolsForPath(
        $symbol_path);
      if (array_intersect_key($req_install, $external_classes)) {
        $need = array_diff_key($need, $req_install);
        unset($requires[$key]);
      }
    }

    foreach ($need as $class => $line) {
      $this->raiseLintAtLine(
        $line,
        0,
        self::LINT_MISSING_DEPENDENCY,
        "This file uses '{$class}' but does not @requires the component ".
        "which installs it. You may need to rebuild the Celerity map.");
    }

    foreach ($requires as $component) {
      $this->raiseLintAtLine(
        0,
        0,
        self::LINT_UNNECESSARY_DEPENDENCY,
        "This file @requires component '{$component}' but does not use ".
        "anything it provides.");
    }
  }

  private function loadSymbols($path) {
    if (empty($this->symbols[$path])) {
      $this->symbols[$path] = $this->newSymbolsFuture($path)->resolvex();
    }
    return $this->symbols[$path];
  }

  private function newSymbolsFuture($path) {
    $root = dirname(phutil_get_library_root('phabricator'));

    $support = $root.'/externals/javelin/support';
    $javelinsymbols = $support.'/javelinsymbols/javelinsymbols';

    $future = new ExecFuture($javelinsymbols.' # '.escapeshellarg($path));
    $future->write($this->getData($path));
    return $future;
  }

  private function getUsedAndInstalledSymbolsForPath($path) {
    list($symbols) = $this->loadSymbols($path);

    $symbols = explode("\n", trim($symbols));

    $uses = array();
    $installs = array();
    foreach ($symbols as $line) {
      $matches = null;
      if (!preg_match('/^([?+])([^:]*):(\d+)$/', $line, $matches)) {
        throw new Exception(
          "Received malformed output from `javelinsymbols`.");
      }
      $type = $matches[1];
      $symbol = $matches[2];
      $line = $matches[3];

      switch ($type) {
        case '?':
          $uses[$symbol] = $line;
          break;
        case '+':
          $installs['JX.'.$symbol] = $line;
          break;
      }
    }

    $contents = $this->getData($path);

    $matches = null;
    $count = preg_match_all(
      '/@javelin-installs\W+(\S+)/',
      $contents,
      $matches,
      PREG_PATTERN_ORDER);

    if ($count) {
      foreach ($matches[1] as $symbol) {
        $installs[$symbol] = 0;
      }
    }

    return array($uses, $installs);
  }

}