import BaseController from '@fleetbase/fleetops-engine/controllers/base-controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { isBlank } from '@ember/utils';

export default class OperationsAdditionalServicesIndexEditController extends BaseController {
    @service fetch;
    @service hostRouter;
    @service notifications;
    @service store;

    @tracked orderConfigs = [];
    @tracked isSaving = false;

    // ─── Form fields ─────────────────────────────────────────────────────────
    @tracked name = '';
    @tracked order_type = null;
    @tracked price = '';
    @tracked description = '';
    @tracked status = 'active';
    @tracked infoText = '';
    @tracked addToQuote = true;

    statusOptions = [
        { label: 'Active',   value: 'active'   },
        { label: 'Inactive', value: 'inactive' },
    ];

    get isValid() {
        return !isBlank(this.name) && !isBlank(this.order_type) && !isBlank(this.price);
    }

    /**
     * Route se model milne ke baad form fields populate karo
     */
    loadFromModel(model) {
        this.name        = model.name        ?? '';
        this.order_type  = model.order_config_uuid ?? null;
        this.price       = model.price       ?? '';
        this.description = model.description ?? '';
        this.status      = model.status      ?? 'active';
        this.infoText    = model.info_text   ?? '';
        this.addToQuote  = model.add_to_quote ?? true;
    }

    @action setOrderType(value) {
        const configId = typeof value === 'string' ? value : value?.id ?? value?.target?.value ?? null;
        this.order_type = configId;
    }

    @action setStatus(value) {
        this.status = typeof value === 'string' ? value : value?.target?.value ?? value;
    }

    @action toggleAddToQuote() {
        this.addToQuote = !this.addToQuote;
    }

    @action transitionBack() {
        return this.hostRouter.transitionTo('console.fleet-ops.operations.additional-services.index');
    }

    @action async saveService() {
        if (!this.isValid) {
            this.notifications.warning('Please fill in all required fields (Name, Order Type, Price).');
            return;
        }

        this.isSaving = true;

        try {
            const model = this.model;

            model.setProperties({
                name:              this.name,
                order_config_uuid: this.order_type,
                price:             parseFloat(this.price),
                description:       this.description,
                status:            this.status,
                info_text:         this.infoText,
                add_to_quote:      this.addToQuote,
            });

            await model.save();

            this.notifications.success(`Additional service "${this.name}" updated successfully.`);
            return this.hostRouter.transitionTo('console.fleet-ops.operations.additional-services.index');

        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            this.isSaving = false;
        }
    }
}