import Route from '@ember/routing/route';

export default class OperationsBusRoutesIndexRoute extends Route {
    model() {
        // Jab tak backend nahi hai, hamesha empty array return karein jisme meta ho
        let records = [];
        records.meta = { current_page: 1, last_page: 1, total: 0 };
        return records;
    }
}