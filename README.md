# plugin-SubsiteImporter
Plugin para importação das entidades de subsites


## Exemplo de configurações

```
'SubsiteImporter' => [
    'namespace' => 'SubsiteImporter',
    'config' => [
        'url_import' => 'http://museus.cultura.gov.br/',
        'query_string' => [
            '@files' => '(avatar,gallery,header):url,description',
            'En_Estado' => 'EQ(PE)',
        ],
        'entities_to_import' => ['space'],
        'get_metadata' => true,
        'import_files' => true,
        'owner_id' => 2,
        'files_grp_import' => ['avatar', 'header'],
        'space_cb' => function($space, $entity){
            $app = \MapasCulturais\App::i();
            $seals = [
                $fva2014 = $app->repo("Seal")->find(10),
                $fva2015 = $app->repo("Seal")->find(11),
                $fva2016 = $app->repo("Seal")->find(12),
                $fva2017 = $app->repo("Seal")->find(13),
                $fva2018 = $app->repo("Seal")->find(14),
                $mus_cad = $app->repo("Seal")->find(16),
                $mus_reg = $app->repo("Seal")->find(15),
            ];
            
            foreach($seals as $seal){
                $seal_name = $seal->name;
                if(($entity->$seal_name ?? "") == "sim"){
                    $space->createSealRelation($seal);
                }
            }

        }
    ]
]
```