@javascript @retry
Feature: Manage Single Link
  As a cms author
  I want to manage link in the CMS

  Background:
    Given a "page" "About Us" has the "Content" "<p>My content</p>"
    And a "image" "assets/file2.jpg"
		And the "group" "AUTHOR" has permissions "Access to 'Pages' section"
    And the "group" "EDITOR" has permissions "Access to 'Pages' section" and "SITETREE_GRANT_ACCESS"
    And I am logged in as a member of "AUTHOR" group
    And I go to "/admin/pages"
    Then I should see "About Us" in the tree

  @javascript
  Scenario: I can open a page for editing from the pages tree
    When I click on "About Us" in the tree
    Then I should see an edit page form