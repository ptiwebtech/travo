import Model, { attr } from '@ember-data/model';
import { computed } from '@ember/object';
import { format, isValid } from 'date-fns';

export default class AdditionalServiceModel extends Model {
    /** @ids */
    @attr('string') public_id;
    @attr('string') company_uuid;
    @attr('string') order_config_uuid;

    /** @attributes */
    @attr('string') name;
    @attr('string') description;
    @attr('string') info_text;
    @attr('number', { defaultValue: 0 }) price;
    @attr('boolean', { defaultValue: true }) add_to_quote;
    @attr('string', { defaultValue: 'active' }) status;

    /** @order type info */
    @attr('string') order_type_name;

    /** @dates */
    @attr('date') created_at;
    @attr('date') updated_at;

    /** @computed */
    @computed('created_at') get createdAtDisplay() {
        if (!isValid(this.created_at)) return '-';
        return format(this.created_at, 'PPP p');
    }

    @computed('status') get isActive() {
        return this.status === 'active';
    }

    @computed('price') get formattedPrice() {
        if (!this.price) return '₦0.00';
        return `₦${parseFloat(this.price).toLocaleString('en-NG', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })}`;
    }
}