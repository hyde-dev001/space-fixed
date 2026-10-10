import React from "react";
import { render, screen } from "@testing-library/react";
import { expect, it } from "vitest";
import { SidebarProvider, useSidebar } from "./SidebarContext";

function ExpandedSectionsProbe() {
  const { expandedSections } = useSidebar();

  return <output data-testid="expanded-sections">{Array.from(expandedSections).join("|")}</output>;
}

it("opens the primary admin sections by default", () => {
  render(
    <SidebarProvider>
      <ExpandedSectionsProbe />
    </SidebarProvider>,
  );

  expect(screen.getByTestId("expanded-sections")).toHaveTextContent(
    "OVERVIEW|PEOPLE & ACCESS|SHOP OPERATIONS|SHOP OWNER APPROVALS",
  );
});
