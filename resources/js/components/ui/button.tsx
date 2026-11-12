import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"
import * as React from "react"

import { cn } from "@/lib/utils"

// shadcn/ui Button mapped onto the Stash master CSS (resources/css/stash.css).
const buttonVariants = cva("", {
  variants: {
    variant: {
      default: "st-btn st-btn--primary",
      destructive: "st-btn st-btn--danger",
      outline: "st-btn st-btn--secondary",
      secondary: "st-btn st-btn--secondary",
      selected: "st-btn st-btn--selected",
      ghost: "st-btn st-btn--ghost",
      link: "st-link inline-flex items-center gap-1 bg-transparent p-0",
    },
    size: {
      default: "",
      sm: "st-btn--sm",
      lg: "st-btn--lg",
      icon: "st-btn--icon",
    },
  },
  defaultVariants: {
    variant: "default",
    size: "default",
  },
})

function Button({
  className,
  variant,
  size,
  asChild = false,
  ...props
}: React.ComponentProps<"button"> &
  VariantProps<typeof buttonVariants> & {
    asChild?: boolean
  }) {
  const Comp = asChild ? Slot : "button"

  return (
    <Comp
      data-slot="button"
      className={cn(buttonVariants({ variant, size }), className)}
      {...props}
    />
  )
}

export { Button, buttonVariants }
