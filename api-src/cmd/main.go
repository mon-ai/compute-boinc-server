package main

import (
	"github.com/labstack/echo/v4"
	"github.com/labstack/echo/v4/middleware"
	"mmon.co/boinc-api/handlers"
)

func main() {
	e := echo.New()

	// middleware
	e.Use(middleware.Logger())
	e.Use(middleware.CORSWithConfig(middleware.CORSConfig{
		AllowOrigins: []string{"https://co.mmon.co"},
		AllowHeaders: []string{echo.HeaderOrigin, echo.HeaderContentType, echo.HeaderAccept},
	}))

	// routing
	e.GET("/healthcheck", handlers.HealthcheckHandler)
	e.POST("/boinc2docker", handlers.Boinc2DockerHandler)

	// start
	s := e.Start(":8000")
	e.Logger.Fatal(s)
}
