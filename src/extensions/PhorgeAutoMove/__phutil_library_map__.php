<?php

/**
 * Phorge AutoMove Extension Library Map
 * 
 * 这个文件告诉 Phorge 如何加载我们的扩展类
 */

phutil_register_library_map(array(
  '__library_version__' => 2,
  'class' => array(
    'UpdateColumnsController' => 'UpdateColumnsController.php',
    'UpdateColumnsService' => 'UpdateColumnsService.php',
    'UpdateColumnsApplication' => 'UpdateColumnsApplication.php',
  ),
  'function' => array(),
  'xmap' => array(
    'UpdateColumnsController' => 'PhabricatorController',
    'UpdateColumnsService' => 'Phobject',
    'UpdateColumnsApplication' => 'PhabricatorApplication',
  ),
));
