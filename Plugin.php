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

    //Executa a importação de entidades
    public function importEntities()
    {
   
        $url =  $this->config['url_import'];
        $_pubKey = $this->config['public_key'];
        $_priKey = $this->config['private_key'];

        $entities_to_import = $this->config['entities_to_import'];
        $params = $this->config['query_string'];
        $params['@limit'] = 50;
        $params['@page'] = 1;

        $api = new \MapasSDK\MapasSDK($url, $_pubKey, $_priKey);

        $this->api = $api;
      
        foreach ($entities_to_import as $type) {

            while ($entities = $api->findEntities($type, '*', $params)) {
                $import_method = "import_{$type}";

                foreach ($entities as $entity) {
                    $user_data = null;
                    
                    if($entity->userId){
                        $user_data = $this->getUserData($entity->userId);
                    }
                    
                    $this->$import_method($entity, $type, $user_data);
                }

                $params['@page']++;
            }
        }
    }

    // Faz a importação dos agentes
    public function import_agent($entity, $type, $user_data = null)
    {
        $app = App::i();

        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '768M');

        $_type = ucfirst($type);

        if ($this->isCreatedEntity($entity, $_type)) {
            return;
        }

        $metadata = [];
        if ($this->config['get_metadata'] && $this->getRegisteredMetadada($_type)) {
            $metadata = $this->getRegisteredMetadada($_type);
        }

        $app->disableAccessControl();
        // Criação do usuário
      
        if (!($user_meta = $app->repo("UserMeta")->findOneBy(['key' => 'imported__originId', 'value' => $entity->userId]))) {
            $user = new User();
            $user->authProvider = 0;
            $user->id = $entity->userId;
            $user->email = $user_data->email;
            $user->status = User::STATUS_ENABLED;
            $user->authUid = $user_data->auth_id;
            $user->lastLoginTimestamp = $user_data->last_auth;
            $user->imported__originId = $entity->userId;
            $user->createTimestamp = $user_data->created_at;
            $user->save(true);
        }else{
            $user = $app->repo("User")->find($user_meta->owner);
        }

        // Criação do agente
        $properties = ['name', 'location', 'public', 'shortDescription', 'longDescription', 'type'];
        $fields = array_merge($properties, $metadata);
       
        $agent = new Agent($user);
        $agent->imported__originId = $entity->id;
        $agent->id = $entity->id;
        $agent->createTimestamp = (new DateTime($entity->createTimestamp->date));
   
        foreach ($fields as $field) {
            if (!isset($entity->$field) || empty($entity->$field)) {
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
            if(!($parent_meta = $app->repo('AgentMeta')->findOneBy(['key' => 'imported__originId', 'value' => $entity->parent]))){
                $agent->imported__parentId = $entity->parent;
                $parente_mess = " - ParentId não foi setado";
            }else{
                $parent = $app->repo("Agent")->find($parent_meta->owner);
                $agent->parent = $parent;
                $parente_mess = " - ParentId {$parent->id}";
            }
        }
        
        $agent->save(true);

        $user->profile = $agent;
        $user->save(true);
        $this->downloadFile($agent, $entity);

        $app->log->debug("Agente {$entity->id} importado com sucesso com id {$agent->id} {$parente_mess}");
        $app->em->clear();

        $app->enableAccessControl();
    }

    // Faz a importação dos espaços
    public function import_space($entity, $type, $user_data = null)
    {
        
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '768M');

        $app = App::i();

        $_type = ucfirst($type);

        if ($this->isCreatedEntity($entity, $_type)) {
            return;
        }

        $metadata = [];
        if ($this->config['get_metadata'] && $this->getRegisteredMetadada($_type)) {
            $metadata = $this->getRegisteredMetadada($_type);
        }

        $properties = ['name', 'location', 'public', 'shortDescription', 'longDescription', 'type'];
        $fields = array_merge($properties, $metadata);


        $owner_id = $this->config['owner_id'];

        /**
         * @TODO Testar bloco abaixo quando implementar importação de agentes
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

    // Verifica se o espaço ja existe na base de dados
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
    
    // Verifica se uma entidade existe já cadastrada de uma importação anterior
    public function isCreatedEntity($entity, $type)
    {
        $app = App::i();
        
       $class = $type."Meta";
        if ($app->repo($class)->findOneBy(['key' => 'imported__originId', 'value' => $entity->id])) {
            $app->log->debug("Entidade {$entity->id} Já foi importada");
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

    // Pega os dados de um usuario para garantir a mesma autenticação
    public function getUserData($userId)
    {
        $app = App::i();

        if($app->rcache->contains("user_data:{$userId}")){
            return $app->rcache->fetch("user_data:{$userId}");
        }

        $uri = "painel/userManagement";

        $curl = $this->api->apiGet($uri, ["userId" => $userId], [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
            'Accept-Language' => 'pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'Cookie' => '_ga=GA1.3.753599530.1658147167; PHPSESSID=86ecnlrae7776dos88r0b565ef; BIGipServerMAPAS_POOL=906668224.20480.0000; mapasculturais.uid=8839; mapasculturais.adm=1; TS01135f6a=01ad235981b983f1cbc78d5f72c66693f552e6785b355768ec618b59aa9ef22f1ba1f17c1e4fd905e6bc9ee28077c3adfeeffb2628c06d7e6733750fcdae8c56e60869c5111587d6487d76eeeffac8511d6dc1904422be6ae1339dbb7152a00a39bf055d14ab957e663fbb14e19451cf8a92d0a298',
            'Host' => 'camacari.ba.mapas.cultura.gov.br',
            'Pragma' => 'no-cache',
            'Referer' =>'http://camacari.ba.mapas.cultura.gov.br/painel/userManagement/',
            'Upgrade-Insecure-Requests' => '1',
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/104.0.0.0 Safari/537.36',
        ]);

        $exp_email = '#<span class="js-editable editable-click editable-empty" data-edit="email" data-original-title="email" data-emptytext="">\s*([^<]*?)\s*</span>#';
        $exp_auth_id = '#<span class="js-editable editable-click editable-empty" data-edit="" data-original-title="id autenticação" data-emptytext="">\s*([^<]*?)\s*</span>#';
        $exp_last_auth = '#<span class="js-editable editable-click editable-empty" data-edit="" data-original-title="último login" data-emptytext="">\s*([^<]*?)\s*</span>#';
        $exp_created_at = '#<span class="js-editable editable-click editable-empty" data-edit="" data-original-title="data criação" data-emptytext="">\s*([^<]*?)\s*</span>#';

        $data = (object)[];
        if(preg_match($exp_email, $curl->response, $m)){
            $data->email = $m[1];
        }

        if(preg_match($exp_auth_id, $curl->response, $m)){
            $data->auth_id = $m[1];
        }

        if(preg_match($exp_last_auth, $curl->response, $m)){
            $exp = explode("às", $m[1]);
            $last_auth = trim($exp[0]). " ". trim($exp[1]);
            $data->last_auth = DateTime::createFromFormat('d/m/Y H:i', $last_auth);
        }

        if(preg_match($exp_created_at, $curl->response, $m)){
            $exp = explode("às", $m[1]);
            $created_at = trim($exp[0]). " ". trim($exp[1]);
            $data->created_at = DateTime::createFromFormat('d/m/Y H:i', $created_at);
        }

        $app->rcache->save("user_data:{$userId}", $data);

        return $data;

    }
}
