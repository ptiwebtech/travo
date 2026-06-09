import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class OperationsBusRoutesIndexEditController extends Controller {
    @service notifications;
    @service store;
    @service hostRouter;

    daysOfWeek = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    @tracked travelTypes = ['Bus', 'Train', 'Ferry'];

    @tracked isSubmitting = false;
    @tracked useSameTime = true;
    @tracked customSchedule = {
        'Monday':    { enabled: true, time: '08:00' },
        'Tuesday':   { enabled: true, time: '08:00' },
        'Wednesday': { enabled: true, time: '08:00' },
        'Thursday':  { enabled: true, time: '08:00' },
        'Friday':    { enabled: true, time: '08:00' },
        'Saturday':  { enabled: true, time: '08:00' },
        'Sunday':    { enabled: true, time: '08:00' }
    };

    @action
    toggleTimeMode() {
        this.useSameTime = !this.useSameTime;
    }

    @action
    toggleDay(day) {
        let selectedDays = this.model.operating_days || [];
        if (selectedDays.includes(day)) {
            selectedDays = selectedDays.filter(d => d !== day);
        } else {
            selectedDays = [...selectedDays, day];
        }
        this.model.set('operating_days', selectedDays);
    }

    @action
    updateSchedule(day, property, event) {
        let value = event.target.type === 'checkbox' ? event.target.checked : event.target.value;
        let currentSchedule = { ...this.customSchedule };
        currentSchedule[day] = { ...currentSchedule[day], [property]: value };
        this.customSchedule = currentSchedule;
        this.model.set('custom_schedule', this.customSchedule);
    }

    @action
    handleVendorSelection(selectedVendor) {
        let vendorId = null;
        if (typeof selectedVendor === 'string') {
            if (selectedVendor.includes(':')) {
                const parts = selectedVendor.split(':');
                vendorId = parts[parts.length - 1].replace('>', '');
            } else {
                vendorId = selectedVendor;
            }
        } else if (selectedVendor && typeof selectedVendor === 'object') {
            vendorId = typeof selectedVendor.get === 'function'
                ? selectedVendor.get('id')
                : selectedVendor.id;
        }
        this.model.set('vendor_uuid', vendorId);
    }

    @action
    selectDepartureLocation(place) {
        this.model.set('departure_location_uuid', place?.id ?? null);
        this.model.set('departure_address', place?.address ?? null);
    }

    @action
    selectArrivalLocation(place) {
        this.model.set('arrival_location_uuid', place?.id ?? null);
        this.model.set('arrival_address', place?.address ?? null);
    }

    @action
    async updateRoute() {
        const { model } = this;
        this.isSubmitting = true;

        if (this.useSameTime) {
            model.set('custom_schedule', []);
        } else {
            model.set('custom_schedule', this.customSchedule);
            const activeDays = Object.keys(this.customSchedule)
                .filter(day => this.customSchedule[day].enabled);
            model.set('operating_days', activeDays);
        }

        if (!model.get('departure_city') || !model.get('arrival_city') || !model.get('vendor_uuid')) {
            this.isSubmitting = false;
            return this.notifications.error('Please fill all required fields.');
        }

        try {
            await model.save();
            this.notifications.success('Bus route updated successfully!');
            this.hostRouter.transitionTo(
                'console.fleet-ops.operations.bus-routes.index.details',
                model
            );
        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            this.isSubmitting = false;
        }
    }

    @action
    onClose() {
        this.hostRouter.transitionTo('console.fleet-ops.operations.bus-routes.index');
    }

    // get travelTypes() {
    //     return ['Bus', 'Train', 'Ferry'];
    // }
}