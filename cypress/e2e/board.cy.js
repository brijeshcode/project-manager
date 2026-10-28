/**
 * Board (kanban) drag-and-drop e2e test.
 * NOTE: Not run locally — Electron binary crashes on this machine.
 * Run via CI or a machine with a working Cypress install.
 */

describe('Kanban board drag-and-drop', () => {
    beforeEach(() => {
        // Seed a project with one task in Backlog via a test-only Artisan route,
        // then log in as a project member.
        cy.artisan('db:seed --class=BoardCypressSeeder');
        cy.login({ email: 'board-user@example.com', password: 'password' });
    });

    it('drags a card from Backlog to Done and persists after reload', () => {
        cy.visit('/projects/1/board');

        // Confirm card starts in Backlog
        cy.get('[data-column="Backlog"] [data-task-id]')
            .first()
            .as('card')
            .should('exist');

        const doneColumn = () => cy.get('[data-column="Done"]');

        // Simulate drag using cypress-drag-drop or plain dataTransfer
        cy.get('@card').trigger('dragstart');
        doneColumn().trigger('dragover').trigger('drop');
        cy.get('@card').trigger('dragend');

        // Wait for Livewire round-trip
        cy.wait(500);

        // Card should now be in Done
        doneColumn().find('[data-task-id]').should('have.length.gte', 1);

        // Reload and assert persistence
        cy.reload();
        doneColumn().find('[data-task-id]').should('have.length.gte', 1);
        cy.get('[data-column="Backlog"] [data-task-id]').should('have.length', 0);
    });
});
