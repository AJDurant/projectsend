<?php
namespace ProjectSend;

use \Laminas\Diactoros\ServerRequestFactory;
use \League\Route\Router;
use \Tamtamchik\SimpleFlash\Flash;
use \ProjectSend\Classes\Locale;
use \ProjectSend\Classes\BruteForceBlock;
use \ProjectSend\Classes\ActionsLog;
use \ProjectSend\Classes\GobalTextStrings;
use \ProjectSend\Classes\Auth;
use \ProjectSend\Classes\AssetsLoader;
use \ProjectSend\Classes\Permissions;
use \ProjectSend\Classes\Csrf;
use \ProjectSend\Classes\Hybridauth;

class Application {
    public $container;

    public function __construct()
    {
        $this->setUpContainer();
        $this->addRouter();
        $this->loadConfigFile();
        $this->loadSystemConstants();
        $this->addContainerSettings();
        $this->addDatabase();
        $this->checkServerRequirements();
        $this->setUpOptions();
        $this->addDependencies();
        $this->addGlobalMiddlewares();
    }

    public function run()
    {
        $this->container->get('dispatcher')->dispatch($this->request);
    }

    private function setUpContainer()
    {
        $this->container = new \DI\Container();
    }

    protected function addContainerSettings()
    {
        $db_config = [];
        if ( defined('DB_NAME') ) {
            $db_config = [
                'driver' => DB_DRIVER,
                'host' => DB_HOST,
                'database' => DB_NAME,
                'username' => DB_USER,
                'password' => DB_PASSWORD,
                'port' => defined('DB_PORT') ? DB_PORT : 3306,
                'charset' => defined('DB_CHARSET') ? DB_CHARSET : 'utf8',
            ];
        }

        $this->container->set('settings', [
            'db' => $db_config,
        ]);
    }

    private function loadConfigFile()
    {
        if ( !file_exists(CONFIG_FILE) ) {
            $router = $this->container->get('router');
            
            header("Cache-control: private");
            $_SESSION = [];
            session_regenerate_id(true);
            session_destroy();
            
            $make_config_file_url = $router->getNamedRoute('install_make_config_file')->getPath();
            $current = $this->request->getUri()->getPath();
            if ($current != $make_config_file_url) {
                ps_redirect($make_config_file_url);
            }

            $this->is_make_config_file = true;
        } else {
            require_once CONFIG_FILE;
        }
    }

    private function checkServerRequirements()
    {
        $this->requirements = new \ProjectSend\Classes\ServerRequirements();
        $error_url = $this->container->get('router')->getNamedRoute('error_requirements')->getPath();
        $current = $this->request->getUri()->getPath();
        if (!$this->requirements->requirementsMet() && $current != $error_url) {
            ps_redirect($error_url);
        }
    }

    private function checkDatabaseRequirements()
    {
        $this->requirements->addDatabase($this->container->get('db'));
    }

    private function addRouter()
    {
        // Router
        $this->request = ServerRequestFactory::fromGlobals(
            $_SERVER, $_GET, $_POST, $_COOKIE, $_FILES
        );

        $router = new Router;
        require_once ROOT_DIR . '/includes/routes.php';
        $this->container->set('router', $router);
    }

    private function loadSystemConstants()
    {
        $constants = new \ProjectSend\Classes\SystemConstants;
    }

    private function addDatabase()
    {
        $this->container->set('db', new \ProjectSend\Classes\Database($this->container->get('settings')['db']));
    }

    public function setUpOptions()
    {
        $this->container->set('options', new \ProjectSend\Classes\Options($this->container->get('db')));
    }

    public function addDependencies()
    {
        $this->container->set('dispatcher', new \ProjectSend\Classes\RoutesDispatcher($this->container->get('router')));
        $this->container->set('flash', new Flash);
        $this->container->set('global_text_strings', new GobalTextStrings($this->container->get('router')));
        $this->container->set('bfchecker', new BruteForceBlock($this->container->get('db'), $this->container->get('options')));
        $this->container->set('locale', new Locale($this->container->get('options')));
        $this->container->set('actions_logger', new ActionsLog($this->container->get('db')));
        $this->container->set('auth', new Auth($this->container->get('db'), $this->container->get('global_text_strings')));
        $this->container->set('assets_loader', new AssetsLoader);
        $this->container->set('permissions', new Permissions);
        $this->container->set('csrf', new Csrf);
        $this->container->set('hybridauth', new Hybridauth($this->container->get('options')));
    }

    public function getContainer()
    {
        return $this->container;
    }

    private function addGlobalMiddlewares()
    {
        if (!isset($this->is_make_config_file)) {
            $this->container->get('router')->middleware(new \ProjectSend\Middleware\IsInstalled($this->container->get('db'), $this->container->get('router')));
        }
    }
}