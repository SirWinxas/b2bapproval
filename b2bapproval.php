<?php
/**
 * Aprobación de Clientes B2B - PrestaShop Module
 * Registro de clientes con aprobación manual del administrador.
 *
 * @author Daniel Gundin
 * @copyright 2026
 * @license MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class B2bapproval extends Module
{
    public function __construct()
    {
        $this->name = 'b2bapproval';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Daniel Gundin';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Aprobación de Clientes B2B');
        $this->description = $this->l('Registro de clientes con aprobación manual del administrador: los nuevos registros quedan pendientes hasta que el administrador los aprueba y les asigna su grupo/tarifa.');
        $this->confirmUninstall = $this->l('¿Estás seguro de que quieres desinstalar este módulo?');
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        if (!$this->registerHook('actionCustomerAccountAdd')) {
            return false;
        }

        $idPendingGroup = $this->createPendingGroup();
        if (!$idPendingGroup) {
            return false;
        }

        $ok = Configuration::updateValue('B2BAPPROVAL_PENDING_GROUP', (int) $idPendingGroup)
            && Configuration::updateValue('B2BAPPROVAL_APPROVED_GROUP', (int) Configuration::get('PS_CUSTOMER_GROUP'))
            && Configuration::updateValue('B2BAPPROVAL_ADMIN_EMAIL', (string) Configuration::get('PS_SHOP_EMAIL'))
            && Configuration::updateValue('B2BAPPROVAL_NOTIFY_ADMIN', 1)
            && Configuration::updateValue('B2BAPPROVAL_EMAIL_CUSTOMER', 1);

        if (!$ok || !$this->installTab()) {
            // Limpieza si algún paso falla
            $this->uninstallTab();
            $this->deleteConfiguration();
            return false;
        }

        return true;
    }

    public function uninstall()
    {
        // El grupo "Pendiente" NO se elimina: puede contener clientes.
        return $this->uninstallTab()
            && $this->deleteConfiguration()
            && parent::uninstall();
    }

    /**
     * Elimina las claves de configuración del módulo
     */
    private function deleteConfiguration()
    {
        $keys = [
            'B2BAPPROVAL_PENDING_GROUP',
            'B2BAPPROVAL_APPROVED_GROUP',
            'B2BAPPROVAL_ADMIN_EMAIL',
            'B2BAPPROVAL_NOTIFY_ADMIN',
            'B2BAPPROVAL_EMAIL_CUSTOMER',
        ];
        foreach ($keys as $key) {
            Configuration::deleteByName($key);
        }
        return true;
    }

    /**
     * Crea el grupo "Pendiente" (o reutiliza uno existente con ese nombre)
     *
     * @return int id del grupo, 0 si falla
     */
    private function createPendingGroup()
    {
        $existing = (int) Db::getInstance()->getValue(
            'SELECT gl.`id_group` FROM `' . _DB_PREFIX_ . 'group_lang` gl
             WHERE gl.`name` = \'' . pSQL('Pendiente') . '\' LIMIT 1'
        );
        if ($existing) {
            return $existing;
        }

        $group = new Group();
        $group->show_prices = 0;
        $group->reduction = 0;
        $group->price_display_method = method_exists('Group', 'getDefaultPriceDisplayMethod')
            ? (int) Group::getDefaultPriceDisplayMethod()
            : 0;
        $group->name = [];
        foreach (Language::getLanguages(false) as $lang) {
            $group->name[(int) $lang['id_lang']] = 'Pendiente';
        }

        if (!$group->save()) {
            return 0;
        }

        return (int) $group->id;
    }

    /**
     * Instala el tab (entrada de menú) en el back office bajo "Clientes"
     */
    private function installTab()
    {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminB2bApproval';
        $tab->module = $this->name;
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminParentCustomer');

        $tab->name = [];
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[(int) $lang['id_lang']] = 'Aprobación B2B';
        }

        return $tab->add();
    }

    /**
     * Desinstala el tab del back office
     */
    private function uninstallTab()
    {
        $idTab = (int) Tab::getIdFromClassName('AdminB2bApproval');
        if ($idTab) {
            $tab = new Tab($idTab);
            return $tab->delete();
        }
        return true;
    }

    // =========================================================================
    // Configuración
    // =========================================================================

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitB2bapprovalConfig')) {
            $output .= $this->postProcessConfig();
        }

        return $output . $this->renderForm();
    }

    /**
     * Valida y guarda la configuración enviada por POST
     */
    private function postProcessConfig()
    {
        $idPending = (int) Tools::getValue('B2BAPPROVAL_PENDING_GROUP');
        $idApproved = (int) Tools::getValue('B2BAPPROVAL_APPROVED_GROUP');
        $email = trim((string) Tools::getValue('B2BAPPROVAL_ADMIN_EMAIL'));
        $notifyAdmin = (int) Tools::getValue('B2BAPPROVAL_NOTIFY_ADMIN') ? 1 : 0;
        $emailCustomer = (int) Tools::getValue('B2BAPPROVAL_EMAIL_CUSTOMER') ? 1 : 0;

        $validIds = [];
        foreach (Group::getGroups((int) $this->context->language->id) as $group) {
            $validIds[] = (int) $group['id_group'];
        }

        if (!in_array($idPending, $validIds, true) || !in_array($idApproved, $validIds, true)) {
            return $this->displayError($this->l('Selecciona grupos válidos.'));
        }
        if ($idPending === $idApproved) {
            return $this->displayError($this->l('El grupo pendiente y el grupo de aprobación deben ser distintos.'));
        }
        if ($email !== '' && !Validate::isEmail($email)) {
            return $this->displayError($this->l('El email del administrador no es válido.'));
        }
        if ($notifyAdmin && $email === '') {
            return $this->displayError($this->l('Indica un email para avisar al administrador.'));
        }

        Configuration::updateValue('B2BAPPROVAL_PENDING_GROUP', $idPending);
        Configuration::updateValue('B2BAPPROVAL_APPROVED_GROUP', $idApproved);
        Configuration::updateValue('B2BAPPROVAL_ADMIN_EMAIL', $email);
        Configuration::updateValue('B2BAPPROVAL_NOTIFY_ADMIN', $notifyAdmin);
        Configuration::updateValue('B2BAPPROVAL_EMAIL_CUSTOMER', $emailCustomer);

        return $this->displayConfirmation($this->l('Configuración guardada correctamente.'));
    }

    /**
     * Formulario de configuración (HelperForm)
     */
    public function renderForm()
    {
        $groups = Group::getGroups((int) $this->context->language->id);

        $switchValues = [
            ['id' => 'active_on', 'value' => 1, 'label' => $this->l('Sí')],
            ['id' => 'active_off', 'value' => 0, 'label' => $this->l('No')],
        ];

        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Configuración de aprobación B2B'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->l('Grupo pendiente'),
                        'name' => 'B2BAPPROVAL_PENDING_GROUP',
                        'desc' => $this->l('Grupo donde quedan los clientes recién registrados hasta ser aprobados.'),
                        'options' => [
                            'query' => $groups,
                            'id' => 'id_group',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Grupo al aprobar'),
                        'name' => 'B2BAPPROVAL_APPROVED_GROUP',
                        'desc' => $this->l('Grupo (tarifa) que se asigna al cliente cuando se aprueba.'),
                        'options' => [
                            'query' => $groups,
                            'id' => 'id_group',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Email del administrador'),
                        'name' => 'B2BAPPROVAL_ADMIN_EMAIL',
                        'desc' => $this->l('Dirección que recibe el aviso de nuevos registros.'),
                        'class' => 'fixed-width-xxl',
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Avisar al administrador'),
                        'name' => 'B2BAPPROVAL_NOTIFY_ADMIN',
                        'is_bool' => true,
                        'values' => $switchValues,
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Avisar al cliente al aprobar'),
                        'name' => 'B2BAPPROVAL_EMAIL_CUSTOMER',
                        'is_bool' => true,
                        'values' => $switchValues,
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Guardar'),
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->submit_action = 'submitB2bapprovalConfig';
        $helper->tpl_vars = [
            'fields_value' => [
                'B2BAPPROVAL_PENDING_GROUP' => (int) Tools::getValue('B2BAPPROVAL_PENDING_GROUP', Configuration::get('B2BAPPROVAL_PENDING_GROUP')),
                'B2BAPPROVAL_APPROVED_GROUP' => (int) Tools::getValue('B2BAPPROVAL_APPROVED_GROUP', Configuration::get('B2BAPPROVAL_APPROVED_GROUP')),
                'B2BAPPROVAL_ADMIN_EMAIL' => (string) Tools::getValue('B2BAPPROVAL_ADMIN_EMAIL', Configuration::get('B2BAPPROVAL_ADMIN_EMAIL')),
                'B2BAPPROVAL_NOTIFY_ADMIN' => (int) Tools::getValue('B2BAPPROVAL_NOTIFY_ADMIN', Configuration::get('B2BAPPROVAL_NOTIFY_ADMIN')),
                'B2BAPPROVAL_EMAIL_CUSTOMER' => (int) Tools::getValue('B2BAPPROVAL_EMAIL_CUSTOMER', Configuration::get('B2BAPPROVAL_EMAIL_CUSTOMER')),
            ],
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => (int) $this->context->language->id,
        ];

        return $helper->generateForm([$fieldsForm]);
    }

    // =========================================================================
    // Utilidades compartidas (también usadas por el controlador de admin)
    // =========================================================================

    /**
     * Reemplaza todos los grupos de un cliente por uno solo (queries directas).
     *
     * El DELETE + INSERT se ejecutan de forma atómica: si el INSERT falla
     * después del DELETE, se revierte y el cliente no queda sin ningún grupo.
     *
     * @param int  $idCustomer
     * @param int  $idGroup
     * @param bool $useTransaction false cuando la transacción la gestiona el
     *                             llamante (p. ej. al aprobar junto con la activación)
     * @return bool true solo si ambas operaciones tuvieron éxito
     */
    public function setCustomerGroup($idCustomer, $idGroup, $useTransaction = true)
    {
        $idCustomer = (int) $idCustomer;
        $idGroup = (int) $idGroup;
        $db = Db::getInstance();

        if ($useTransaction) {
            $db->execute('START TRANSACTION');
        }

        $ok = $db->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'customer_group` WHERE `id_customer` = ' . $idCustomer
        );
        $ok = $ok && $db->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'customer_group` (`id_customer`, `id_group`)
             VALUES (' . $idCustomer . ', ' . $idGroup . ')'
        );

        if ($useTransaction) {
            $db->execute($ok ? 'COMMIT' : 'ROLLBACK');
        }

        return (bool) $ok;
    }

    /**
     * Devuelve un id de idioma para el que existan plantillas de correo del módulo
     * (es / en); si el idioma pedido no tiene plantillas, usa español o inglés.
     */
    public function getMailLangId($idLang)
    {
        $iso = Language::getIsoById((int) $idLang);
        if ($iso && is_dir(_PS_MODULE_DIR_ . $this->name . '/mails/' . $iso)) {
            return (int) $idLang;
        }
        foreach (['es', 'en'] as $fallback) {
            $id = (int) Language::getIdByIso($fallback);
            if ($id) {
                return $id;
            }
        }
        return (int) $idLang;
    }

    /**
     * Envía una plantilla de correo del módulo
     */
    public function sendModuleMail($idLang, $template, $subject, array $vars, $to, $toName = null)
    {
        return Mail::Send(
            $this->getMailLangId($idLang),
            $template,
            $subject,
            $vars,
            $to,
            $toName,
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . $this->name . '/mails/'
        );
    }

    // =========================================================================
    // Hook
    // =========================================================================

    /**
     * Tras el registro: el cliente queda desactivado y en el grupo pendiente.
     */
    public function hookActionCustomerAccountAdd($params)
    {
        try {
            if (empty($params['newCustomer']) || !($params['newCustomer'] instanceof Customer)) {
                return;
            }

            $customer = $params['newCustomer'];
            $idPending = (int) Configuration::get('B2BAPPROVAL_PENDING_GROUP');
            if (!$idPending || !Validate::isLoadedObject($customer)) {
                return;
            }

            $customer->active = 0;
            $customer->id_default_group = $idPending;
            $customer->update();

            // Un único grupo: el pendiente
            $this->setCustomerGroup((int) $customer->id, $idPending);

            if ((int) Configuration::get('B2BAPPROVAL_NOTIFY_ADMIN')) {
                $adminEmail = (string) Configuration::get('B2BAPPROVAL_ADMIN_EMAIL');
                if ($adminEmail !== '' && Validate::isEmail($adminEmail)) {
                    $this->sendModuleMail(
                        (int) $this->context->language->id,
                        'b2bapproval_pending',
                        'Nuevo registro pendiente de aprobación',
                        [
                            '{firstname}' => $customer->firstname,
                            '{lastname}' => $customer->lastname,
                            '{email}' => $customer->email,
                            '{company}' => $customer->company ? $customer->company : '-',
                        ],
                        $adminEmail
                    );
                }
            }

            // PrestaShop inicia sesión automáticamente tras el registro, antes de
            // ejecutar este hook. Cerramos la sesión aquí para que el bloqueo sea
            // efectivo de inmediato y el cliente no navegue como si estuviera aprobado.
            if (isset($this->context->customer) && $this->context->customer) {
                $this->context->customer->logout();
            }
            if (isset($this->context->cookie) && $this->context->cookie) {
                $this->context->cookie->logout();
            }
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                'b2bapproval: error en el registro de cliente: ' . $e->getMessage(),
                3,
                null,
                'Customer',
                isset($params['newCustomer']->id) ? (int) $params['newCustomer']->id : null,
                true
            );
        }
    }
}
