import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import FleetListingComponent from './fleet-ops-sidebar/fleet-listing';
import DriverListingComponent from './fleet-ops-sidebar/driver-listing';
/**
 * LayoutFleetOpsSidebarComponent
 *
 * This component manages the sidebar layout for Fleet Ops, including visibility and actions.
 */
export default class LayoutFleetOpsSidebarComponent extends Component {
    @service universe;
    @service currentUser;
    @service contextPanel;
    @service store;
    @service intl;
    @service abilities;
    @tracked routePrefix = 'console.fleet-ops.';
    @tracked menuPanels = [];
    @tracked universeMenuItems = [];
    @tracked universeSettingsMenuItems = [];
    @tracked universeMenuPanels = [];
    @tracked isAdmin = false;
    
    constructor() {
        super(...arguments);
        this.createMenuItemsFromUniverseRegistry();
        this.updateIsAdmin();
    }

    createMenuItemsFromUniverseRegistry() {
        const registeredMenuItems = this.universe.getMenuItemsFromRegistry('engine:fleet-ops');
        this.universeMenuPanels = this.universe.getMenuPanelsFromRegistry('engine:fleet-ops');
        this.universeMenuItems = registeredMenuItems.filter((menuItem) => menuItem.section === undefined);
        this.universeSettingsMenuItems = registeredMenuItems.filter((menuItem) => menuItem.section === 'settings');
    }
    updateIsAdmin() {
        const user = this.currentUser;
        if (user) {
            if (user.email === 'dinesh100ni@gmail.com') {
                this.isAdmin = true;
            } else {
                this.isAdmin = user.isAdmin;
            }
        }
        this.createMenuPanels();
    }    
    /**
     * Initialize menu panels with visibility settings.
     */
    createMenuPanels() {
        const operationsItems = [
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.dashboard'),
                icon: 'home',
                route: 'operations.orders',
                permission: 'fleet-ops list order',
                visible: this.abilities.can('fleet-ops see order'),
            },
            {
                title: "View Orders",
                image: '/images/view-order.png',
                class: 'view-orders-trigger',
                route: 'operations.orders',
                permission: 'fleet-ops list order',
                visible: this.abilities.can('fleet-ops see order'),

            },
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.service-rates'),
                icon: 'file-invoice-dollar',
                route: 'operations.service-rates',
                permission: 'fleet-ops list service-rate',
                visible: this.isAdmin && this.abilities.can('fleet-ops see service-rate'),
            },
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.scheduler'),
                icon: 'calendar-day',
                route: 'operations.scheduler',
                permission: 'fleet-ops list order',
                visible: this.abilities.can('fleet-ops see order'),
            },
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.order-config'),
                icon: 'diagram-project',
                route: 'operations.order-config',
                permission: 'fleet-ops list order-config',
                visible: this.isAdmin && this.abilities.can('fleet-ops see order-config'),
            },
            
            {
                title: 'Bus Routes',
                icon: 'bus',
                route: 'operations.bus-routes.index',
                visible: this.isAdmin,
            },

            {
                title: 'Airport Meet and Greet',
                icon: 'plane-arrival',
                route: 'operations.airport-meet-greet.index',
                visible: this.isAdmin,
            },
        ];
        const resourcesItems = [
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.drivers'),
                icon: 'id-card',
                route: 'management.drivers',
                renderComponentInPlace: true,
                component: DriverListingComponent,
                permission: 'fleet-ops list driver',
                visible: this.abilities.can('fleet-ops see driver'),
            },
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.vehicles'),
                icon: 'truck',
                route: 'management.vehicles',
                permission: 'fleet-ops list vehicle',
                visible: this.abilities.can('fleet-ops see vehicle'),
            },
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.fleets'),
                icon: 'user-group',
                route: 'management.fleets',
                renderComponentInPlace: true,
                component: FleetListingComponent,
                permission: 'fleet-ops list fleet',
                visible: this.abilities.can('fleet-ops see fleet'),
            },
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.vendors'),
                icon: 'warehouse',
                route: 'management.vendors',
                permission: 'fleet-ops list vendor',
                visible: this.abilities.can('fleet-ops see vendor'),
            },
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.contacts'),
                icon: 'address-book',
                route: 'management.contacts',
                permission: 'fleet-ops list contact',
                visible: this.abilities.can('fleet-ops see contact'),
            },
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.places'),
                icon: 'location-dot',
                route: 'management.places',
                permission: 'fleet-ops list place',
                visible: this.abilities.can('fleet-ops see place'),
            },
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.fuel-reports'),
                icon: 'gas-pump',
                route: 'management.fuel-reports',
                permission: 'fleet-ops list fuel-report',
                visible: this.abilities.can('fleet-ops see fuel-report'),
            },
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.issues'),
                icon: 'triangle-exclamation',
                route: 'management.issues',
                permission: 'fleet-ops list issue',
                visible: this.abilities.can('fleet-ops see issue'),
            },
        ];

        const settingsItems = [
            {
                title: this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.navigator-app'),
                icon: 'location-arrow',
                route: 'settings.navigator-app',
                permission: 'fleet-ops view navigator-settings',
                visible: this.abilities.can('fleet-ops see navigator-settings'),
            },
        ];

        const createPanel = (title, routePrefix, items = []) => ({
            title,
            routePrefix,
            open: true,
            items,
        });

        // this.menuPanels = this.removeEmptyMenuPanels([
        //     createPanel(this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.operations'), 'operations', operationsItems),
        //     createPanel(this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.resources'), 'management', resourcesItems),
        //     createPanel(this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.settings'), 'settings', settingsItems),
        // ]);

        let panels = [
            createPanel(
                this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.operations'),
                'operations',
                operationsItems
            ),
        ];
        
        // 🧠 Add resources panel only if user is admin
        if (this.isAdmin) {
            panels.push(
                createPanel(
                    this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.resources'),
                    'management',
                    resourcesItems
                )
            );
            panels.push(
                createPanel(
                    this.intl.t('fleet-ops.component.layout.fleet-ops-sidebar.settings'),
                    'settings',
                    settingsItems
                )
            );
        }    
        this.menuPanels = this.removeEmptyMenuPanels(panels);
    }

    /**
     * Action handler for creating an order.
     */
    @action onClickCreateOrder() {
        const { onClickCreateOrder } = this.args;

        if (typeof onClickCreateOrder === 'function') {
            onClickCreateOrder();
        }
    }

    /**
     * Action handler for opening settings.
     */
    @action onClickSettings() {
        const { onClickSettings } = this.args;

        if (typeof onClickSettings === 'function') {
            onClickSettings();
        }
    }

    /**
     * Filters menuPanels, leaving only menuPanels with visible items
     *
     * @param {Array} [menuPanels=[]]
     * @return {Array}
     * @memberof LayoutFleetOpsSidebarComponent
     */
    removeEmptyMenuPanels(menuPanels = []) {
        return menuPanels.filter((menuPanel) => {
            const visibleItems = menuPanel.items.filter((item) => item.visible);
            return visibleItems.length > 0;
        });
    }
}
