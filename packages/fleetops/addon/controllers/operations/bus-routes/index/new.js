import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class OperationsBusRoutesIndexNewController extends Controller {
    @service notifications;
    @service store;

    @service router;        // ✅ ADD KARO
    @service hostRouter; 

    daysOfWeek = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    @tracked travelTypes = ['Bus', 'Train', 'Ferry'];

    @tracked isSubmitting = false;
    @tracked useSameTime = true;

    @tracked customSchedule = {
        'Monday': { enabled: true, time: '08:00' },
        'Tuesday': { enabled: true, time: '08:00' },
        'Wednesday': { enabled: true, time: '08:00' },
        'Thursday': { enabled: true, time: '08:00' },
        'Friday': { enabled: true, time: '08:00' },
        'Saturday': { enabled: true, time: '08:00' },
        'Sunday': { enabled: true, time: '08:00' }
    };

    @action
    updateSchedule(day, property, event) {
        let value = event.target.type === 'checkbox' ? event.target.checked : event.target.value;
        let currentSchedule = { ...this.customSchedule };
        
        currentSchedule[day] = { 
            ...currentSchedule[day], 
            [property]: value 
        };
        
        this.customSchedule = currentSchedule;

        // Model mein data save karne ke liye ready karein
        this.model.set('advanced_schedule', this.customSchedule);
    }

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

    /**
     * ✅ UPDATED: Ab ye asali data save karega database mein
     */
    @action 
    async saveRoute() {
        const { model } = this;
        this.isSubmitting = true;
    
        // 1. Get IDs from current state
        let vId = model.get('vendor.id') || model.get('vendor_uuid');
        let dId = model.get('departure_location.id') || model.get('departure_location_uuid');
        let aId = model.get('arrival_location.id') || model.get('arrival_location_uuid');
    
        const cleanId = (id) => {
            if (typeof id === 'string' && id.includes(':')) {
                const parts = id.split(':');
                return parts[parts.length - 1].replace('>', '');
            }
            return id;
        };
    
        // 2. Explicitly set these on the model so they go in the payload
        model.setProperties({
            vendor_uuid: cleanId(vId),
            departure_location_uuid: cleanId(dId),
            arrival_location_uuid: cleanId(aId),
            // Add company_uuid if you have it on frontend, else backend handles it
        });
    
        if (this.useSameTime) {
            model.set('custom_schedule', []);
        } else {
            model.set('custom_schedule', this.customSchedule);
            const activeDays = Object.keys(this.customSchedule).filter(day => this.customSchedule[day].enabled);
            model.set('operating_days', activeDays);
        }
    
        // Validation
        if (!model.get('departure_city') || !model.get('arrival_city') || !model.get('vendor_uuid')) {
            this.isSubmitting = false;
            return this.notifications.error('Please fill all required fields.');
        }
    
        try {
            // model.save() will send { busRoute: { ... } } or { bus-route: { ... } }
            await model.save();
            this.notifications.success('Bus route created successfully!');
            
            if (this.onAfterSave) {
                this.onAfterSave(model);
            }
            this.hostRouter.transitionTo('console.fleet-ops.operations.bus-routes.index');
            //this.hostRouter.transitionTo('console.fleet-ops.operations.bus-routes.index.details', model.id);
        } catch (error) {
            console.error("Backend Error:", error);
            this.notifications.serverError(error);
        } finally {
            this.isSubmitting = false;
        }
    }

    @action
    handleVendorSelection(selectedVendor) {
        let vendorId = null;
    
        if (typeof selectedVendor === 'string') {
            // String format: "<model::vendor:979ce3ee...>"
            if (selectedVendor.includes(':')) {
                const parts = selectedVendor.split(':');
                vendorId = parts[parts.length - 1].replace('>', '');
            } else {
                vendorId = selectedVendor;
            }
        } else if (selectedVendor && typeof selectedVendor === 'object') {
            // Agar Ember object hai
            vendorId = typeof selectedVendor.get === 'function' ? selectedVendor.get('id') : selectedVendor.id;
        }
    
        if (vendorId) {
            this.model.set('vendor_uuid', vendorId);
            const vendorRecord = this.store.peekRecord('vendor', vendorId);
            if (vendorRecord) {
                this.model.set('vendor', vendorRecord);
            }
        } else {
            this.model.set('vendor_uuid', null);
            this.model.set('vendor', null);
        }
    }

    // get travelTypes() {
    //     return ['Bus', 'Train', 'Ferry'];
    // }

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
}