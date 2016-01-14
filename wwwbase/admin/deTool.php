<?php
require_once("../../phplib/util.php"); 
util_assertModerator(PRIV_EDIT);
util_assertNotMirror();

define('SOURCE_ID', 25); // Dicționarul enciclopedic

$definitionId = util_getRequestParameter('definitionId');
$jumpPrefix = util_getRequestParameterWithDefault('jumpPrefix', '');
$butTest = util_getRequestParameter('butTest');
$butSave = util_getRequestParameter('butSave');
$butNext = util_getRequestParameter('butNext');
$lexemIds = util_getRequestParameter('lexemId');
$models = util_getRequestParameter('models');
$capitalize = util_getBoolean('capitalize');
$deleteOrphans = util_getBoolean('deleteOrphans');

if ($definitionId) {
  $def = Definition::get_by_id($definitionId);
} else {
  // Load the first definition after $jumpPrefix from DE 
  $def = Model::factory('Definition')
       ->where('sourceId', SOURCE_ID)
       ->where('status', Definition::ST_ACTIVE)
       ->where_gte('lexicon', $jumpPrefix)
       ->order_by_asc('lexicon')
       ->order_by_asc('id')
       ->find_one();
}

if (!$def) {
  exit;
}

// Load the next definition ID
$next = Model::factory('Definition')
  ->where('sourceId', SOURCE_ID)
  ->where('status', Definition::ST_ACTIVE)
  ->where_raw('((lexicon > ?) or (lexicon = ? and id > ?))',
              [$def->lexicon, $def->lexicon, $def->id])
  ->order_by_asc('lexicon')
  ->order_by_asc('id')
  ->find_one();
$nextId = $next ? $next->id : 0;

// Load the database lexems
$dbl = Model::factory('Lexem')
     ->select('Lexem.*')
     ->join('LexemDefinitionMap', 'Lexem.id = lexemId', 'ldm')
     ->where('ldm.definitionId', $def->id)
     ->order_by_asc('formNoAccent')
     ->find_many();
$dblIds = util_objectProperty($dbl, 'id');

$passedTests = false;

if ($butSave) {
  // Dissociate all lexems
  LexemDefinitionMap::deleteByDefinitionId($def->id);

  foreach ($lexemIds as $i => $lid) {
    if ($lid) {
      // Create a new lexem or load the existing one
      if (StringUtil::startsWith($lid, '@')) {
        $lexem = Lexem::create(substr($lid, 1));
        $lexem->save();
      } else {
        $lexem = Lexem::get_by_id($lid);
      }

      // Associate the lexem with the definition
      LexemDefinitionMap::associate($lexem->id, $def->id);

      // Delete existing lexem models
      LexemModel::delete_all_by_lexemId($lexem->id);

      // Associate given models
      $lms = [];
      foreach (explode(',', $models[$i]) as $m) {
        $model = Model::factory('ModelType')
               ->select('code')
               ->select('number')
               ->join('Model', ['canonical', '=', 'modelType'])
               ->where_raw("concat(code, number) = ? ", [$m])
               ->find_one();
        $lm = LexemModel::create($model->code, $model->number);
        $lm->lexemId = $lexem->id;
        $lm->displayOrder = 1 + count($lms);
        $lm->setLexem($lexem);
        $lms[] = $lm;
      }
      $lexem->setLexemModels($lms);
      $lexem->deepSave();
    }
  }

  // Delete orphaned lexems
  if ($deleteOrphans) {
    foreach ($dbl as $l) {
      $ldms = LexemDefinitionMap::get_all_by_lexemId($l->id);
      if (!count($ldms)) {
        $l->delete();
      }
    }
  }

  // Redirect back to the page
  $target = sprintf("?definitionId=%d&capitalize=%d&deleteOrphans=%d",
                    $def->id,
                    (int)$capitalize,
                    (int)$deleteOrphans);
  util_redirect($target);

} else if ($butTest) {
  try {
    if (!count($lexemIds)) {
      throw new Exception('Trebuie să asociați cel puțin un lexem.');
    }

    foreach ($lexemIds as $i => $lid) {
      if (empty($lid) xor empty($models[$i])) {
        throw new Exception('Nu puteți avea un lexem fără modele nici invers.');
      }

      if ($lid) {
        if (StringUtil::startsWith($lid, '@')) {
          $lexem = Model::factory('Lexem')->create();
          $lexem->form = substr($lid, 1);
        } else {
          $lexem = Lexem::get_by_id($lid);
        }

        // Check that the lexem works with every model
        foreach (explode(',', $models[$i]) as $m) {
          $model = Model::factory('ModelType')
                 ->select('code')
                 ->select('number')
                 ->join('Model', ['canonical', '=', 'modelType'])
                 ->where_raw("concat(code, number) = ? ", [$m])
                 ->find_one();
          $lm = LexemModel::create($model->code, $model->number);
          $lm->setLexem($lexem);
          $ifs = $lm->generateInflectedForms();
          if (!is_array($ifs)) {
            $infl = Inflection::get_by_id($ifs);
            $msg = "Lexemul „%s” nu poate fi flexionat conform modelului %s";
            throw new Exception(sprintf($msg, $lexem->form, $m));
          }
        }
      }
    }
    $passedTests = true;
  } catch (Exception $e) {
    SmartyWrap::assign('errorMessage', $e->getMessage());
  }
  SmartyWrap::assign('lexemIds', $lexemIds);
  SmartyWrap::assign('models', $models);
} else {
  $models = [];
  foreach ($dbl as $l) {
    $m = [];
    foreach($l->getLexemModels() as $lm) {
      $m[] = "{$lm->modelType}{$lm->modelNumber}";
    }
    $models[] = implode(',', $m);
  }
  
  SmartyWrap::assign('lexemIds', $dblIds);
  SmartyWrap::assign('models', $models);
}

SmartyWrap::assign('def', $def);
SmartyWrap::assign('nextId', $nextId);
SmartyWrap::assign('capitalize', $capitalize);
SmartyWrap::assign('deleteOrphans', $deleteOrphans);
SmartyWrap::assign('passedTests', $passedTests);
SmartyWrap::addCss('jqueryui', 'select2');
SmartyWrap::addJs('jquery', 'jqueryui', 'select2', 'select2Dev', 'deTool');
SmartyWrap::displayAdminPage('admin/deTool.tpl');
