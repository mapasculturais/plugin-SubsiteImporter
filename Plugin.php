<?php

namespace SubsiteImporter;

use DateTime;
use MapasCulturais\App;
use MapasCulturais\Entity;
use MapasCulturais\Entities\User;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\Space;

class Plugin extends \MapasCulturais\Plugin
{
    static $instance = null;

    function __construct(array $config = [])
    {
        $config += [
            'url_import' => '',
            'query_string' => [],
            'public_key' => null,
            'private_key' => null,
            'entities_to_import' => [],
            'get_metadata' => false,
            'owner_id' => null,
            'import_files' => false,
            'files_grp_import' => false,
            'space_cb' => function(){},
            'subsite_importer_password' => "",
            'header_get_userdata' => []
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

        if(isset($_GET['subsiteimporterupdateparent']) && ($_GET['subsiteimporterupdateparent'] == $this->config['subsite_importer_password'])){   
            $this->parentIdRelation();
        }

        $app->hook("entity(Agent).insert:after", function() use ($app){
            if(isset($_GET['subsiteimporterpassword']) && ($_GET['subsiteimporterpassword'] == $this->config['subsite_importer_password'])){ 
                if($this->id != $this->imported__originId){
                    $this->id = $this->originId;
                    $this->imported__originId = $this->originId;
                    $app->em->persist($this);
                    $this->refresh();
                }
            }
        });
    }

    public function register()
    {
        $cfgOriginId = [
            'label' => 'Id da entidade na origem',
            'type' => 'int'
        ];

        $this->registerAgentMetadata('imported__originId', $cfgOriginId);
        $this->registerSpaceMetadata('imported__originId', $cfgOriginId);
        $this->registerUserMetadata('imported__originId', $cfgOriginId);

        $cfgParentId = [
            'label' => 'Registra o ID parent que o agente tem',
            'type' => 'int'
        ];

        $this->registerAgentMetadata('imported__parentId', $cfgParentId);
    }

    protected $api;

    //Executa a importa????o de entidades
    public function importEntities()
    {
   
        $url =  $this->config['url_import'];
        $_pubKey = $this->config['public_key'];
        $_priKey = $this->config['private_key'];

        $entities_to_import = $this->config['entities_to_import'];
        $params = $this->config['query_string'];
        $params['@limit'] = 400;
        $params['@page'] = 1;

        $api = new \MapasSDK\MapasSDK($url, $_pubKey, $_priKey);

        $this->api = $api;
      
        foreach ($entities_to_import as $type) {

            while ($entities = $api->findEntities($type, '*', $params)) {

                $import_method = "import_{$type}";

                foreach ($entities as $entity) {

                    $_type = ucfirst($type);

                    if ($this->isCreatedEntity($entity, $_type)) {
                        continue;
                    }

                    $user_data = null;
                   
                    if(isset($entity->userId) && $entity->userId){
                        $user_data = $this->getUserData($entity->userId);

                        if(!$user_data){
                           continue; 
                        }

                    }
                 
                    $this->$import_method($entity, $type, $user_data);
                }

                $params['@page']++;
            }
        }
    }

    // Faz a importa????o dos agentes
    public function import_agent($entity, $type, $user_data = null)
    {
        $app = App::i();

        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '768M');

        $_type = ucfirst($type);


        $metadata = [];
        if ($this->config['get_metadata'] && $this->getRegisteredMetadada($_type)) {
            $metadata = $this->getRegisteredMetadada($_type);
        }

        $app->disableAccessControl();
        // Cria????o do usu??rio
      
        if (!($user_meta = $app->repo("UserMeta")->findOneBy(['key' => 'imported__originId', 'value' => $entity->userId]))) {
            $user = new User();
            $user->authProvider = 0;
            $user->id = $entity->userId;
            $user->email = $user_data->email ?: $entity->emailPrivado;
            $user->status = User::STATUS_ENABLED;
            $user->authUid = $user_data->auth_id;
            $user->lastLoginTimestamp = $user_data->last_auth;
            $user->imported__originId = $entity->userId;
            $user->createTimestamp = $user_data->created_at;
            $user->save(true);
        }else{
            $user = $app->repo("User")->find($user_meta->owner);
        }

        // Cria????o do agente
        $properties = ['name', 'location', 'publicLocation', 'shortDescription', 'longDescription', 'type'];
        $fields = array_merge($properties, $metadata);
       
        $conn = $app->em->getConnection();
        $conn->insert("agent", [
            'id' => $entity->id,
            'name' => "",
            'short_description' => "",
            'type' => 1,
            'user_id' => $user->id,
            'create_timestamp' => (new DateTime($entity->createTimestamp->date))->format("Y-m-d H:i:s"),
            'status' => $entity->status
        ]);

        $agent = $app->repo("Agent")->find($entity->id);
        $agent->_newCreatedRevision();

        foreach ($fields as $field) {
            if (!isset($entity->$field)) {
                continue;
            }

            if ($field == "type") {
                $agent->type = $entity->$field->id;
            } else {
                $agent->$field = $entity->$field;
            }
        }
        
        
        $parente_mess = "";
        if($entity->parent){
            $agent->imported__parentId = $entity->parent;
        }
        
        $agent->save(true);

        $user->profile = $agent;
        $user->save(true);
        $this->downloadFile($agent, $entity);

        $app->log->debug("Agente {$entity->id} importado com sucesso com id {$agent->id} {$parente_mess}");
        $app->em->clear();

        $app->enableAccessControl();
    }

    // Faz a importa????o dos espa??os
    public function import_space($entity, $type, $user_data = null)
    {
        
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '768M');

        $app = App::i();

        $_type = ucfirst($type);

        $metadata = [];
        if ($this->config['get_metadata'] && $this->getRegisteredMetadada($_type)) {
            $metadata = $this->getRegisteredMetadada($_type);
        }

        $properties = ['name', 'location', 'public', 'shortDescription', 'longDescription', 'type'];
        $fields = array_merge($properties, $metadata);


        $owner_id = $this->config['owner_id'];

        /**
         * @TODO Testar bloco abaixo quando implementar importa????o de agentes
         */
        if ($agent_meta = $app->repo('AgentMeta')->findOneBy(['key' => 'imported__originId', 'value' => $entity->owner])) {
            $owner_id = $agent_meta->owner;
        }

        $owner = $app->repo('Agent')->find($owner_id);

        if(!$space = $this->spaceExist($entity)){
            $space = new Space();
        }

        $space->owner = $owner;
        $space->imported__originId = $entity->id;

        $app->disableAccessControl();
        foreach ($fields as $field) {

            if (!isset($entity->$field)) {
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

        $app->log->debug("Entidade {$entity->id} importada com sucesso");

        $app->enableAccessControl();
    }

    // Faz o dowload de arquivos tipo avatar e header
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

    // Verifica se o espa??o ja existe na base de dados
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

        $app->log->debug("Entidade {$entity->id} j?? esta cadastrada com ID {$space->id}");
        return $space;
    }
    
    // Verifica se uma entidade existe j?? cadastrada de uma importa????o anterior
    public function isCreatedEntity($entity, $type)
    {
        $app = App::i();
        
       $class = $type."Meta";
        if ($app->repo($class)->findOneBy(['key' => 'imported__originId', 'value' => $entity->id])) {
            $app->log->debug("Entidade {$entity->id} J?? foi importada");
            $app->em->clear();
            return true;
        }

        return false;
    }

    // pega os metadados registrados de uma entidade
    public function getRegisteredMetadada($type)
    {
        $class = "MapasCulturais\\Entities\\{$type}";
        if(class_exists($class)){
            $_class = new $class();
            return array_keys($_class::getMetadataMetadata());
        }
        return false;
    }

    // Pega os dados de um usuario para garantir a mesma autentica????o
    public function getUserData($userId)
    {
       
        $app = App::i();

        if(!$this->config['header_get_userdata']){
            $app->log->debug("Cookie para acesso aos dados de autentica????o n??o foi definido");
            return;
        }


        if($app->rcache->contains("user_data:{$userId}")){
            return $app->rcache->fetch("user_data:{$userId}");
        }

        $uri = "painel/userManagement";

        $curl = $this->api->apiGet($uri, ["userId" => $userId], $this->config['header_get_userdata']);

        $exp_email = '#<span class="js-editable editable-click editable-empty" data-edit="email" data-original-title="email" data-emptytext="">\s*([^<]*?)\s*</span>#';
        $exp_auth_id = '#<span class="js-editable editable-click editable-empty" data-edit="" data-original-title="id autentica????o" data-emptytext="">\s*([^<]*?)\s*</span>#';
        $exp_last_auth = '#<span class="js-editable editable-click editable-empty" data-edit="" data-original-title="??ltimo login" data-emptytext="">\s*([^<]*?)\s*</span>#';
        $exp_created_at = '#<span class="js-editable editable-click editable-empty" data-edit="" data-original-title="data cria????o" data-emptytext="">\s*([^<]*?)\s*</span>#';
        
        $data = (object)[
            'email' => null,
            'auth_id' => null,
            'last_auth' => null,
            'created_at' => null,
            'created_at' => null,
        ];

        if(preg_match($exp_email, $curl->response, $m)){
            $data->email = $m[1];
        }

        if(preg_match($exp_auth_id, $curl->response, $m)){
            $data->auth_id = $m[1];
        }

        if(preg_match($exp_last_auth, $curl->response, $m)){
            $exp = explode("??s", $m[1]);
            $last_auth = trim($exp[0]). " ". trim($exp[1]);
            $data->last_auth = DateTime::createFromFormat('d/m/Y H:i', $last_auth);
        }

        if(preg_match($exp_created_at, $curl->response, $m)){
            $exp = explode("??s", $m[1]);
            $created_at = trim($exp[0]). " ". trim($exp[1]);
            $data->created_at = DateTime::createFromFormat('d/m/Y H:i', $created_at);
        }

        $app->rcache->save("user_data:{$userId}", $data);

        return $data;

    }

    //Relaciona os agentes que estavam com parentid definido na api com os seus agentes originais
    public function parentIdRelation()
    {
        $app = App::i();
        $conn = $app->em->getConnection();

        if ($parents = $conn->fetchAll("select * from agent_meta am where am.key = 'imported__parentId'")) {
            
            $relations = [];
            foreach ($parents as $parent) {
                $relations[$parent['object_id']] = $parent['value'];
            }

            if ($relations) {
                foreach ($relations as $key => $value) {
                    $parent_meta = $app->repo('AgentMeta')->findOneBy(['key' => 'imported__originId', 'value' => $value]);
                    
                    if($parent = $app->repo("Agent")->find($parent_meta->owner->id)){
                        $app->disableAccessControl();
                        $agent = $app->repo("Agent")->find($key);
                        $agent->parent = $parent;
                        $agent->save(true);
                        $app->enableAccessControl();
                    }
                }
            }
        }
    }
}
