<?php

namespace SubsiteImporter;

use MapasCulturais\App;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\Space;
use MapasCulturais\Entity;


class Plugin extends \MapasCulturais\Plugin
{
    static $instance = null;

    function __construct(array $config = [])
    {
        $config += [
            'url_import' => '',
            'query_string' => [],
            'entities_to_import' => [],
            'get_metadata' => false,
            'owner_id' => null,
            'import_files' => false,
            'files_grp_import' => false,
            'space_cb' => function(){},
            'subsite_importer_password' => ""
        ];

        parent::__construct($config);
        
        self::$instance = $this;
    }

    public function _init()
    {
        $app = App::i();

        $self = $this;

        if(isset($_GET['subsiteimporterpassword']) && ($_GET['subsiteimporterpassword'] == $this->config['subsite_importer_password'])){
            $app->hook('mapasculturais.run:after', function() use ($self){
                $self->importEntities();

            });
        }
    }

    public function register()
    {
        $cfgOriginId = [
            'label' => 'Id da entidade na origem',
            'type' => 'int'
        ];

        $this->registerAgentMetadata('imported__originId', $cfg);
        $this->registerSpaceMetadata('imported__originId', $cfg);
    }

    public function importEntities()
    {
        $url =  $this->config['url_import'];
        $entities_to_import = $this->config['entities_to_import'];
        $params = $this->config['query_string'];
        $params['@limit'] = 50;
        $params['@page'] = 1;

        $api = new \MapasSDK\MapasSDK($url);

        foreach ($entities_to_import as $type) {

            while ($entities = $api->findEntities($type, '*', $params)) {
                $import_method = "import_{$type}";

                foreach ($entities as $entity) {
                    $this->$import_method($entity);
                }

                $params['@page']++;
            }
        }
    }

    public function import_space($entity)
    {
        
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '768M');

        $app = App::i();

        if ($app->repo('SpaceMeta')->findOneBy(['key' => 'imported__originId', 'value' => $entity->id])) {
            $app->log->debug("Entidade {$entity->id} Já foi importada");
            return;
        }

        $metadata = [];
        if ($this->config['get_metadata']) {
            $metadata = array_keys(Space::getMetadataMetadata());
        }

        $properties = ['name', 'location', 'public', 'shortDescription', 'longDescription', 'type'];
        $fields = array_merge($properties, $metadata);


        $owner_id = $this->config['owner_id'];

        /**
         * @TODO Testar bloco abaixo quando implementar importação de agentes
         */
        if ($agent_meta = $app->repo('AgentMeta')->findOneBy(['key' => 'imported__originId', 'value' => $entity->owner])) {
            // $owner_id = $agent_meta->value;
        }

        $owner = $app->repo('Agent')->find($owner_id);

        if(!$space = $this->spaceExist($entity)){
            $space = new Space();
        }

        $space->owner = $owner;
        $space->imported__originId = $entity->id;

        $app->disableAccessControl();
        foreach ($fields as $field) {

            if (!isset($entity->$field) || empty($entity->$field)) {
                continue;
            }

            if ($field == "type") {
                $space->type = $entity->$field->id;
            } else {
                $space->$field = $entity->$field;
            }
        }

        $app->user = $owner->user;

        $space->save();
        
        $this->downloadFile($space, $entity);

        $cb = $this->config['space_cb'];
        $cb($space, $entity);

        $app->em->clear();
        $app->log->debug("Entidade {$entity->id} importada com sucesso");

        $app->enableAccessControl();
    }

    protected function downloadFile(Entity $owner, $entity)
    {
        if (!$this->config['import_files']) {
            return;
        }

        $_entity = json_decode(json_encode($entity), true);

        $files_grp_import = $this->config['files_grp_import'];

        foreach ($files_grp_import as $grp_import) {
            $grp = "@files:{$grp_import}";

            if (in_array($grp, array_keys($_entity))) {
                $_file = $_entity[$grp];

                $basename = basename($_file["url"]);
                $file_data["url"] = str_replace($basename, urlencode($basename), $_file["url"]);

                $ch = curl_init($file_data["url"]);
                $tmp = tempnam("/tmp", "");
                $handle = fopen($tmp, "wb");

                curl_setopt($ch, CURLOPT_FILE, $handle);

                if (!curl_exec($ch)) {
                    fclose($handle);
                    unlink($tmp);
                    return false;
                }

                curl_close($ch);
                $sz = ftell($handle);
                fclose($handle);

                $class_name = $owner->fileClassName;

                $file = new $class_name([
                    "name" => $basename,
                    "type" => mime_content_type($tmp),
                    "tmp_name" => $tmp,
                    "error" => 0,
                    "size" => filesize($tmp)
                ]);

                $file->group = $grp_import;
                $file->owner = $owner;
                $file->save(true);
            }
        }
    }

    public function spaceExist($entity)
    {
        $app = App::i();

        $_name = trim($entity->name);

        if(!$space = $app->repo("Space")->findOneBy(['name' => $_name])){
            return false;
        }

        $fields_verify = [
            "En_Num" ,
            "En_Bairro" ,
        ];

        foreach($fields_verify as $field){
            $field_e = str_replace(" ", "", mb_strtolower($entity->$field));
            $field_s = str_replace(" ", "", mb_strtolower($space->$field));

            if($field_e != $field_s){
                return false;
            }

        }

        $app->log->debug("Entidade {$entity->id} já esta cadastrada com ID {$space->id}");
        return $space;
    }
}
