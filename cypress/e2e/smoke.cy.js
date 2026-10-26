describe("smoke", () => {
  it("loads the login page", () => {
    cy.visit("/login");
    cy.contains(/log in/i);
  });
});
