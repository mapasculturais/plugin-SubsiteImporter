<?php
return [
    'Importando entidades do subsite' => function(){
       $plugin = SubsiteImporter\Plugin::$instance;
       $plugin->importEntities();
       return false;
    }
];