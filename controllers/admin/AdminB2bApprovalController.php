<?php
/**
 * Back Office Controller - Aprobación de Clientes B2B
 *
 * Lista los clientes pendientes de aprobación (inactivos y en el grupo
 * pendiente) y permite aprobarlos o rechazarlos con acciones estándar
 * de HelperList (enlaces con token de administrador).
 */

class AdminB2bApprovalController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'customer';
        $this->className = 'Customer';
        $this->identifier = 'id_customer';
        $this->lang = false;
        $this->list_no_link = true;
        $this->allow_export = false;

        parent::__construct();

        $idPending = (int) Configuration::get('B2BAPPROVAL_PENDING_GROUP');

        // Solo clientes pendientes: inactivos y con el grupo pendiente por defecto
        $this->_select = '';
        $this->_where = ' AND a.`active` = 0 AND a.`deleted` = 0 AND a.`is_guest` = 0'
            . ' AND a.`id_default_group` = ' . $idPending
            . Shop::addSqlRestriction(Shop::SHARE_CUSTOMER, 'a');

        $this->_orderBy = 'id_customer';
        $this->_orderWay = 'DESC';

        $this->fields_list = [
            'id_customer' => [
                'title' => $this->l('ID'),
                'align' => 'text-center',
                'class' => 'fixed-width-xs',
            ],
            'firstname' => [
                'title' => $this->l('Nombre'),
            ],
            'lastname' => [
                'title' => $this->l('Apellidos'),
            ],
            'email' => [
                'title' => $this->l('Email'),
            ],
            'company' => [
                'title' => $this->l('Empresa'),
            ],
            'date_add' => [
                'title' => $this->l('Fecha de registro'),
                'type' => 'datetime',
                'align' => 'text-right',
            ],
        ];

        $this->actions = ['approve', 'reject'];
    }

    /**
     * Quita el botón "Añadir nuevo" de la barra de herramientas
     */
    public function initToolbar()
    {
        parent::initToolbar();
        unset($this->toolbar_btn['new']);
    }

    public function initContent()
    {
        parent::initContent();
    }

    public function renderList()
    {
        return parent::renderList();
    }

    // =========================================================================
    // Enlaces de acción por fila
    // =========================================================================

    public function displayApproveLink($token = null, $id = null, $name = null)
    {
        return $this->buildActionLink('approveb2b', (int) $id, 'icon-check', $this->l('Aprobar'), $token, 'btn btn-default');
    }

    public function displayRejectLink($token = null, $id = null, $name = null)
    {
        return $this->buildActionLink('rejectb2b', (int) $id, 'icon-remove', $this->l('Rechazar'), $token, 'btn btn-default');
    }

    private function buildActionLink($param, $id, $icon, $label, $token, $class)
    {
        $url = self::$currentIndex . '&' . $this->identifier . '=' . (int) $id
            . '&' . $param . '=1&token=' . ($token ? $token : $this->token);

        return '<a class="' . $class . '" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
            . '" title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">'
            . '<i class="' . $icon . '"></i> ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    }

    // =========================================================================
    // Procesado de acciones
    // =========================================================================

    public function postProcess()
    {
        if (Tools::getIsset('approveb2b') || Tools::getIsset('rejectb2b')) {
            if (method_exists($this, 'access') && !$this->access('edit')) {
                $this->errors[] = $this->l('No tienes permisos para realizar esta acción.');
                return parent::postProcess();
            }

            $idCustomer = (int) Tools::getValue('id_customer');
            $customer = new Customer($idCustomer);

            if (!Validate::isLoadedObject($customer)) {
                $this->errors[] = $this->l('Cliente no encontrado.');
            } elseif (Tools::getIsset('approveb2b')) {
                $this->approveCustomer($customer);
            } else {
                $this->rejectCustomer($customer);
            }
        }

        return parent::postProcess();
    }

    private function approveCustomer(Customer $customer)
    {
        $idApproved = (int) Configuration::get('B2BAPPROVAL_APPROVED_GROUP');
        if (!$idApproved) {
            $this->errors[] = $this->l('Configura primero el grupo de aprobación en el módulo.');
            return;
        }

        // Activación y cambio de grupo en una sola transacción: o ambas cosas,
        // o ninguna. Evita dejar al cliente activo pero sin ningún grupo.
        $db = Db::getInstance();
        $committed = false;

        try {
            $db->execute('START TRANSACTION');

            $customer->active = 1;
            $customer->id_default_group = $idApproved;

            $ok = $customer->update()
                && $this->module->setCustomerGroup((int) $customer->id, $idApproved, false);

            if (!$ok) {
                $db->execute('ROLLBACK');
                $this->errors[] = $this->l('No se pudo aprobar el cliente (activación o asignación de grupo); no se ha aplicado ningún cambio.');
                return;
            }

            $db->execute('COMMIT');
            $committed = true;
        } catch (\Throwable $e) {
            if (!$committed) {
                $db->execute('ROLLBACK');
            }
            $this->errors[] = $this->l('Error al aprobar el cliente: ') . $e->getMessage();
            return;
        }

        // La aprobación ya está confirmada en BD; el email es un extra no crítico.
        if ((int) Configuration::get('B2BAPPROVAL_EMAIL_CUSTOMER')) {
            $sent = $this->module->sendModuleMail(
                (int) $customer->id_lang,
                'b2bapproval_approved',
                'Tu cuenta ha sido aprobada',
                [
                    '{firstname}' => $customer->firstname,
                    '{lastname}' => $customer->lastname,
                    '{login_url}' => $this->context->link->getPageLink('authentication', true, (int) $customer->id_lang),
                ],
                $customer->email,
                $customer->firstname . ' ' . $customer->lastname
            );
            if (!$sent) {
                $this->warnings[] = $this->l('El cliente se ha aprobado, pero no se pudo enviar el email.');
            }
        }

        $this->confirmations[] = sprintf(
            $this->l('Cliente %s aprobado correctamente.'),
            $customer->firstname . ' ' . $customer->lastname
        );
    }

    /**
     * Rechazar = mantener el cliente desactivado (no se elimina).
     */
    private function rejectCustomer(Customer $customer)
    {
        try {
            $customer->active = 0;
            if (!$customer->update()) {
                $this->errors[] = $this->l('No se pudo actualizar el cliente.');
                return;
            }
            $this->confirmations[] = sprintf(
                $this->l('Cliente %s rechazado: permanece desactivado.'),
                $customer->firstname . ' ' . $customer->lastname
            );
        } catch (\Throwable $e) {
            $this->errors[] = $this->l('Error al rechazar el cliente: ') . $e->getMessage();
        }
    }
}
