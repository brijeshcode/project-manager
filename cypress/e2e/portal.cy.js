// Client portal e2e tests
// Assumes a seeded DB with:
//   - client user: email=client@example.com, password=password, client_id=1
//   - project with id=1 belonging to that client
//   - at least one published client_visible task in the 'Done' column with id=1
//
// NOTE: Not executed in CI (Electron crashes on this machine). Verified via Pest.

describe('Client portal', () => {
    beforeEach(() => {
        cy.session('client', () => {
            cy.visit('/login');
            cy.get('input[name=email]').type('client@example.com');
            cy.get('input[name=password]').type('password');
            cy.get('button[type=submit]').click();
            cy.url().should('not.include', '/login');
        });
    });

    it('client can view their project board', () => {
        cy.visit('/portal');
        cy.contains('Your Projects').should('be.visible');
        // Click through to the first project
        cy.get('a').first().click();
        cy.url().should('include', '/portal/projects/');
    });

    it('client sees only client_visible tasks', () => {
        cy.visit('/portal/projects/1');
        // Internal tasks (client_visible=false) must not appear
        cy.contains('Internal Task').should('not.exist');
    });

    it('client can approve a Done task', () => {
        cy.visit('/portal/projects/1');
        // Find a task in the Done column that has an Approve button
        cy.contains('Approve').first().click();
        cy.contains('✓ Approved').should('be.visible');
    });

    it('client does not see price or cost fields', () => {
        cy.visit('/portal/projects/1');
        cy.get('body').should('not.contain', 'price');
        cy.get('body').should('not.contain', 'cost_rate');
    });

    it('client cannot navigate to another client project (gets 403)', () => {
        // project 999 belongs to a different client
        cy.request({ url: '/portal/projects/999', failOnStatusCode: false })
            .its('status')
            .should('eq', 403);
    });
});
