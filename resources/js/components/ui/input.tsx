import * as React from "react"

import { cn } from "@/lib/utils"

// shadcn/ui Input mapped onto the Stash master CSS (.st-input).
function Input({ className, type, ...props }: React.ComponentProps<"input">) {
  return (
    <input
      type={type}
      data-slot="input"
      className={cn("st-input", className)}
      {...props}
    />
  )
}

export { Input }
