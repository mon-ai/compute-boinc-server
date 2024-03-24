package handlers

import (
	"github.com/labstack/echo/v4"
)

func HealthcheckHandler(c echo.Context) error {
	return c.JSON(200, map[string]any{
		"ok": true,
	})
}
