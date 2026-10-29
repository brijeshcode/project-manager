describe('Client CRUD', () => {
  beforeEach(() => {
    cy.artisan('db:seed', { class: 'RoleSeeder' });
    cy.createUser({ role: 'super_admin' }).then((user) => {
      cy.login(user);
    });
  });

  it('super admin can create a client', () => {
    cy.visit('/clients/create');

    cy.get('input#name').type('Acme Corp');
    cy.get('input#currency').type('USD');
    cy.contains('button', 'Create').click();

    cy.url().should('include', '/clients');
    cy.contains('Acme Corp').should('be.visible');
    cy.contains('USD').should('be.visible');
  });

  it('shows validation error when currency is missing', () => {
    cy.visit('/clients/create');

    cy.get('input#name').type('No Currency Corp');
    cy.contains('button', 'Create').click();

    cy.contains('currency').should('be.visible');
  });
});
