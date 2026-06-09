import BaseController from '@fleetbase/fleetops-engine/controllers/base-controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { isBlank } from '@ember/utils';

export default class OperationsAdditionalServicesIndexNewController extends BaseController {
    @service fetch;
    @service intl;
    @service hostRouter;
    @service notifications;
    @service modalsManager;
    @service store;

    // ─── Populated by the route's setupController ───────────────────────────
    /**
     * All order-config records — fed in by the route, not @fromStore,
     * so the route controls the load timing (same pattern as orders/new).
     * @var {Array}
     */
    @tracked orderConfigs = [];

    /**
     * The matched order-config object (set by route when ?orderType= is present)
     * @var {Object|null}
     */
    @tracked selectedConfig = null;

    // ─── Form fields ─────────────────────────────────────────────────────────

    /**
     * Is the form currently saving?
     * @var {Boolean}
     */
    @tracked isSaving = false;

    /**
     * Service name
     * @var {String}
     */
    @tracked name = '';

    /**
     * Selected order-config UUID (bound to the Order Type dropdown)
     * @var {String|null}
     */
    @tracked order_type = null;

    /**
     * Price in Naira
     * @var {String}
     */
    @tracked price = '';

    /**
     * Description (shown to users in the order form)
     * @var {String}
     */
    @tracked description = '';

    /**
     * Status: 'active' | 'inactive'
     * @var {String}
     */
    @tracked status = 'active';

    /**
     * Text shown in the ⓘ info tooltip next to this toggle in the order form.
     * Leave blank to hide the info button entirely.
     * @var {String}
     */
    @tracked infoText = '';

    /**
     * When true, selecting this service auto-adds its price to the order quote.
     * @var {Boolean}
     */
    @tracked addToQuote = true;

    // ─── Static options ───────────────────────────────────────────────────────

    /**
     * Status dropdown options
     */
    statusOptions = [
        { label: 'Active',   value: 'active'   },
        { label: 'Inactive', value: 'inactive' },
    ];

    // ─── Computed ─────────────────────────────────────────────────────────────

    /**
     * Form is valid when the three required fields are filled.
     * @returns {Boolean}
     */
    get isValid() {
        return !isBlank(this.name) && !isBlank(this.order_type) && !isBlank(this.price);
    }

    // ─── Actions ──────────────────────────────────────────────────────────────

    /**
     * Reset all form fields to their defaults.
     * Called by the route on willTransition and setupController.
     */
    resetForm() {
        this.name           = '';
        this.order_type     = null;
        this.selectedConfig = null;
        this.price          = '';
        this.description    = '';
        this.status         = 'active';
        this.infoText       = '';
        this.addToQuote     = true;
        this.isSaving       = false;
    }

    /**
     * Handle Order Type dropdown change.
     * Also updates selectedConfig so the preview panel can show the config name.
     * @param {String} configId
     */
    @action setOrderType(value) {
        let configId;
    
        if (typeof value === 'string') {
            // ✅ Direct UUID string aa raha hai
            configId = value;
        } else if (value && typeof value === 'object' && value.id) {
            // ✅ Object aa raha hai
            configId = value.id;
        } else if (value?.target?.value) {
            // ✅ Native event aa raha hai
            configId = value.target.value;
        } else {
            configId = null;
        }
    
        this.order_type     = configId;
        this.selectedConfig = this.orderConfigs.find((c) => c.id === configId) ?? null;
    }

    /**
     * Handle Status dropdown change.
     * @param {String} value  'active' | 'inactive'
     */
    @action setStatus(value) {
        this.status = value;
    }

    /**
     * Toggle the "Add to Price Quote" flag.
     */
    @action toggleAddToQuote() {
        this.addToQuote = !this.addToQuote;
    }

    /**
     * Navigate back to the Additional Services index without saving.
     */
    @action transitionBack() {
        return this.transitionToRoute('operations.additional-services.index');
    }

    /**
     * Persist the new additional service.
     *
     * Currently uses a simulated delay while the backend is being built.
     * When the API is ready:
     *   1. Remove the Promise timeout block
     *   2. Uncomment the store.createRecord / service.save() block below
     */
    @action async saveService() {
        if (!this.isValid) {
            this.notifications.warning('Please fill in all required fields (Name, Order Type, Price).');
            return;
        }
    
        this.isSaving = true;
    
        try {
            const model = this.store.createRecord('additional-service', {
                name:              this.name,
                order_config_uuid: this.order_type,
                price:             parseFloat(this.price),
                description:       this.description,
                status:            this.status,
                info_text:         this.infoText,
                add_to_quote:      this.addToQuote,
            });
    
            await model.save();
    
            this.notifications.success(`Additional service "${this.name}" added successfully.`);
            this.resetForm();
            return this.transitionToRoute('operations.additional-services.index');
    
        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            this.isSaving = false;
        }
    }
}