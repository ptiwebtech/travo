import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action, getProperties } from '@ember/object';
import OnboardValidations from '../../validations/onboard';
import lookupValidator from 'ember-changeset-validations';
import Changeset from 'ember-changeset';

export default class OnboardIndexController extends Controller {
    /**
     * Inject the `fetch` service
     *
     * @memberof OnboardIndexController
     */
    @service fetch;

    /**
     * Inject the `session` service
     *
     * @memberof OnboardIndexController
     */
    @service session;

    /**
     * Inject the `router` service
     *
     * @memberof OnboardIndexController
     */
    @service router;

    /**
     * Inject the `notifications` service
     *
     * @memberof OnboardIndexController
     */
    @service notifications;

    /**
     * The name input field.
     *
     * @memberof OnboardIndexController
     */
    @tracked name;

    /**
     * The email input field.
     *
     * @memberof OnboardIndexController
     */
    @tracked email;

    /**
     * The phone input field.
     *
     * @memberof OnboardIndexController
     */
    @tracked phone;

    /**
     * The organization_name input field.
     *
     * @memberof OnboardIndexController
     */
    @tracked organization_name;

    /**
     * The password input field.
     *
     * @memberof OnboardIndexController
     */
    @tracked password;

    /**
     * The name password confirmation field.
     *
     * @memberof OnboardIndexController
     */
    @tracked password_confirmation;

    /**
     * The property for error message.
     *
     * @memberof OnboardIndexController
     */
    @tracked error;

    /**
     * The loading state of the onboard request.
     *
     * @memberof OnboardIndexController
     */
    @tracked isLoading = false;

    /**
     * The ready state for the form.
     *
     * @memberof OnboardIndexController
     */
    @tracked readyToSubmit = false;

    /**
     * Start the onboard process.
     *
     * @return {Promise}
     * @memberof OnboardIndexController
     */
    @action async startOnboard(event) {
        event.preventDefault();

        // eslint-disable-next-line ember/no-get
        const input = getProperties(this, 'name', 'email', 'phone', 'organization_name', 'password', 'password_confirmation');
        const changeset = new Changeset(input, lookupValidator(OnboardValidations), OnboardValidations);

        await changeset.validate();

        if (changeset.get('isInvalid')) {
            const errorMessage = changeset.errors.firstObject.validation.firstObject;

            this.notifications.error(errorMessage);
            return;
        }

        // Set user timezone
        input.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

        this.isLoading = true;

        return this.fetch
            .post('fltonboard/create-account', input)
            .then((response) => {
                try {
                    // Ensure the response is in JSON format
                    //const data = JSON.parse(response);
                    const data = response;
                    // Destructure the necessary data
                    const { status, skipVerification, token, session } = data;

                    if (status === 'success') {
                        if (skipVerification === true && token) {
                            // Manually authenticate if skip verification is true
                            this.session.isOnboarding().manuallyAuthenticate(token);

                            return this.router.transitionTo('console').then(() => {
                                this.notifications.success('Welcome to Fleetbase!');
                            });
                        }

                        // Redirect to email verification if no skip verification
                        return this.router.transitionTo('onboard.verify-email', { queryParams: { hello: session } });
                    }
                } catch (e) {
                    console.error('Response is not valid JSON:', response);
                    throw new Error('Invalid JSON response');
                }
            })
            .catch((error) => {
                console.error('API error:', error);
                this.notifications.serverError(error);
            })
            .finally(() => {
                this.isLoading = false;
            });
    }
}
