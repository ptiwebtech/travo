import BaseController from '@fleetbase/fleetops-engine/controllers/base-controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { isBlank } from '@ember/utils';
import { timeout } from 'ember-concurrency';
import { task } from 'ember-concurrency-decorators';
import fromStore from '@fleetbase/ember-core/decorators/from-store';

export default class OperationsAdditionalServicesIndexController extends BaseController {
    @service fetch;
    @service intl;
    @service hostRouter;
    @service notifications;
    @service modalsManager;
    @service crud;

    /**
     * Queryable parameters
     * @var {Array}
     */
    queryParams = [
        'page',
        'limit',
        'sort',
        'query',
        'order_type',
        'status',
    ];

    /**
     * Current page
     * @var {Integer}
     */
    @tracked page = 1;

    /**
     * Items per page
     * @var {Integer}
     */
    @tracked limit;

    /**
     * Sort param
     * @var {String}
     */
    @tracked sort = '-created_at';

    /**
     * Search query
     * @var {String}
     */
    @tracked query;

    /**
     * Filter by order type
     * @var {String}
     */
    @tracked order_type;

    /**
     * Filter by status
     * @var {String}
     */
    @tracked status;

    /**
     * Search visibility flag
     * @var {Boolean}
     */
    @tracked isSearchVisible = false;

    /**
     * All available order configs for filter dropdown
     */
    @fromStore('order-config', { limit: -1 }) orderConfigs;

    /**
     * Table columns
     * @var {Array}
     */
    @tracked columns = [
        {
            label: 'Id',
            valuePath: 'public_id',
            width: '140px',
            cellComponent: 'table/cell/link-to',
            route: 'operations.additional-services.index.details',
            onLinkClick: this.viewService, 
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Service Name',
            valuePath: 'name',
            width: '200px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/string',
        },
        {
            label: 'Order Type',
            valuePath: 'order_type_name',
            cellComponent: 'table/cell/base',
            width: '180px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/select',
            filterOptions: this.orderConfigs,
            filterOptionLabel: 'name',
            filterOptionValue: 'id',
            filterParam: 'order_type',
            filterComponentPlaceholder: 'Filter by Order Type',
        },
        {
            label: 'Price (₦)',
            valuePath: 'price',
            cellComponent: 'table/cell/base',
            width: '120px',
            resizable: true,
            sortable: true,
        },
        {
            label: 'Status',
            valuePath: 'status',
            cellComponent: 'table/cell/status',
            width: '100px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/multi-option',
            filterOptions: [
                { label: 'Active', value: 'active' },
                { label: 'Inactive', value: 'inactive' },
            ],
        },
        {
            label: 'Created At',
            valuePath: 'createdAtDisplay',
            sortParam: 'created_at',
            filterParam: 'created_at',
            width: '140px',
            resizable: true,
            sortable: true,
            filterable: true,
            filterComponent: 'filter/date',
        },
        {
            label: '',
            cellComponent: 'table/cell/dropdown',
            ddButtonText: false,
            ddButtonIcon: 'ellipsis-h',
            ddButtonIconPrefix: 'fas',
            ddMenuLabel: 'Actions',
            cellClassNames: 'overflow-visible',
            wrapperClass: 'flex items-center justify-end mx-2',
            width: '10%',
            actions: [
                {
                    label: 'Edit Service',
                    icon: 'pencil',
                    fn: this.editService,
                },
                {
                    label: 'View Details',
                    icon: 'eye',
                    fn: this.viewService,
                },
                {
                    label: 'Delete Service',
                    icon: 'trash',
                    fn: this.deleteService,
                },
            ],
            sortable: false,
            filterable: false,
            resizable: false,
            searchable: false,
        },
    ];

    /**
     * Search task
     */
    @task({ restartable: true }) *search({ target: { value } }) {
        if (isBlank(value)) {
            this.query = null;
            return;
        }
        yield timeout(250);
        if (this.page > 1) {
            this.page = 1;
        }
        this.query = value;
    }

    /**
     * Reload list
     */
    @action reload() {
        return this.hostRouter.refresh();
    }

    /**
     * Toggle search bar
     */
    @action toggleSearch() {
        this.isSearchVisible = !this.isSearchVisible;
    }

    /**
     * Create new additional service
     */
    @action createService() {
        return this.transitionToRoute('operations.additional-services.index.new');
    }

    /**
     * Edit existing service
     */
    @action editService(service) {
        return this.transitionToRoute('operations.additional-services.index.edit', service);
    }

    /**
     * details existing service
     */
    @action viewService(service) {
        return this.transitionToRoute('operations.additional-services.index.details', service);
    }

    /**
     * Delete a service
     */
    @action deleteService(service, options = {}) {
        this.crud.delete(service, {
            onSuccess: () => {
                return this.hostRouter.refresh();
            },
            ...options,
        });
    }

    /**
     * Bulk delete services
     */
    @action bulkDeleteServices(selected = []) {
        selected = selected.length > 0 ? selected : this.table.selectedRows;

        this.crud.bulkDelete(selected, {
            modelNamePath: 'name',
            acceptButtonText: 'Delete Services',
            onSuccess: async () => {
                await this.hostRouter.refresh();
                this.table.untoggleSelectAll();
            },
        });
    }
}